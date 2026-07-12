<?php
declare(strict_types=1);

namespace App\Ai;

use App\Core\HttpClient;
use RuntimeException;

/**
 * OpenAI-compatible LLM provider — speaks the standard /v1/chat/completions
 * REST API. Works with OpenAI, any OpenAI-proxy (Anthropic API in proxy mode,
 * Groq, Together, etc.), Ollama, vLLM, and every other provider that exposes
 * the same wire format.
 *
 * chat()  uses HttpClient (SSRF-protected, retries idempotent-on-failure).
 * stream() uses raw cURL + curl_multi for real-time SSE parsing; it yields
 * AiStreamChunk objects as each delta arrives.
 *
 * Usage:
 *   $provider = new OpenAiProvider(['api_key' => 'sk-...']);
 *   $response = $provider->chat('gpt-4o', [
 *       ['role' => 'user', 'content' => 'Hello!'],
 *   ]);
 *   echo $response->content;
 *
 * Testing: pass a $poster callable to intercept the HTTP request (same
 * pattern as MailgunTransport's $poster in app/Mail).
 *
 * @see https://platform.openai.com/docs/api-reference/chat
 */
final class OpenAiProvider implements AiProvider
{
    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly string $defaultModel;
    private readonly int $timeout;

    /**
     * @param array<string, mixed> $config
     *        api_key  — OpenAI API key (or a key for the compatible provider)
     *        base_url — API root, default https://api.openai.com/v1
     *        default_model — fallback when none is passed to chat()/stream()
     *        timeout — request timeout in seconds, default 60
     * @param (callable(string, array<string, mixed>, list<string>): array{status: int, body: string})|null $poster
     *        Test-only: when set, called instead of HttpClient for chat().
     *        Receives (url, jsonPayload, headersList). Return {status, body}.
     */
    public function __construct(
        array $config = [],
        private readonly ?HttpClient $http = null,
        private readonly mixed $poster = null,
    ) {
        $this->apiKey  = (string) ($config['api_key'] ?? '');
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.openai.com/v1'), '/');
        if ($this->baseUrl === '' || $this->apiKey === '') {
            throw new RuntimeException('OpenAiProvider: api_key and base_url are required');
        }
        $this->defaultModel = (string) ($config['default_model'] ?? 'gpt-4o');
        $this->timeout      = (int) ($config['timeout'] ?? 60);
    }

    public function name(): string
    {
        return 'openai';
    }

    /**
     * Send a chat completion request and return the full (non-streaming) response.
     * Uses HttpClient for SSRF protection, retries, and connection pooling.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     */
    public function chat(string $model, array $messages, array $options = []): AiResponse
    {
        $body = $this->buildBody($model, $messages, $options);
        $url  = $this->baseUrl . '/chat/completions';
        $bodyJson = json_encode($body);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        if ($this->poster !== null) {
            $result = ($this->poster)($url, $body, $headers);
            $status = $result['status'];
            $data   = json_decode($result['body'], true);
        } else {
            $response = ($this->http ?? new HttpClient([
                'timeout'        => $this->timeout,
                'connect_timeout' => 30,
                'http_errors'    => false,
            ]))->post($url, [
                'json'    => $body,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
            ]);

            $status = $response->getStatusCode();
            $data   = $response->json();
        }

        $data = is_array($data) ? $data : [];

        if ($status < 200 || $status >= 300) {
            $errMsg = $data['error']['message'] ?? 'Unknown API error';
            throw new RuntimeException("OpenAi API returned HTTP $status: $errMsg");
        }

        $choice    = $data['choices'][0] ?? [];
        $message   = $choice['message'] ?? [];
        $usage     = $data['usage'] ?? [];

        return AiResponse::with(
            content:      (string) ($message['content'] ?? ''),
            model:        (string) ($data['model'] ?? $model),
            inputTokens:  isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            outputTokens: isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
            finishReason: $choice['finish_reason'] ?? null,
        );
    }

    /**
     * Stream a chat completion via SSE. Yields AiStreamChunk objects as each
     * delta arrives, using curl_multi so the caller can process chunks in
     * real-time rather than waiting for the full response.
     *
     * Usage:
     *   foreach ($provider->stream('gpt-4o', $messages) as $chunk) {
     *       echo $chunk->delta;
     *   }
     *
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @return \Generator<AiStreamChunk>
     */
    public function stream(string $model, array $messages, array $options = []): \Generator
    {
        $body = $this->buildBody($model, $messages, $options);
        $body['stream'] = true;
        $body['stream_options'] = ['include_usage' => true];
        $bodyJson = json_encode($body);
        if ($bodyJson === false) {
            throw new RuntimeException('OpenAiProvider: failed to JSON-encode request body');
        }

        $url = $this->baseUrl . '/chat/completions';
        self::assertSafeUrl($url);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL               => $url,
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => $bodyJson,
            CURLOPT_HTTPHEADER        => $headers,
            CURLOPT_RETURNTRANSFER    => false,
            CURLOPT_PROTOCOLS         => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT           => $this->timeout,
            CURLOPT_CONNECTTIMEOUT    => 30,
            CURLOPT_SSL_VERIFYPEER    => true,
            CURLOPT_FOLLOWLOCATION    => true,
            CURLOPT_MAXREDIRS         => 3,
        ]);

        $queue = new \SplQueue();
        $curlError = null;
        $httpCode = null;

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, string $data) use ($queue): int {
            static $buffer = '';
            $buffer .= $data;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                $chunk = self::parseSseLine($line);
                if ($chunk !== null) {
                    $queue->enqueue($chunk);
                }
            }

            return strlen($data);
        });

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);

        $active = null;
        do {
            while (($status = curl_multi_exec($mh, $active)) === CURLM_CALL_MULTI_PERFORM) {
                // spin until cURL is ready to yield data
            }

            if ($status !== CURLM_OK) {
                $curlError = 'curl_multi error: ' . curl_multi_strerror($status);
                break;
            }

            // Yield every chunk that arrived in this tick
            while (!$queue->isEmpty()) {
                yield $queue->dequeue();
            }

            if ($active > 0) {
                curl_multi_select($mh, 0.1);
            }
        } while ($active > 0);

        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno  = curl_errno($ch);
        if ($curlErrno) {
            $curlError = curl_error($ch);
        }

        curl_multi_remove_handle($mh, $ch);
        curl_multi_close($mh);
        curl_close($ch);

        if ($curlError !== null) {
            throw new RuntimeException('OpenAi stream error: ' . $curlError);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("OpenAi API returned HTTP $httpCode");
        }

        // Yield any remaining queued chunks
        while (!$queue->isEmpty()) {
            yield $queue->dequeue();
        }
    }

    /**
     * Build the request body array from the model, messages, and options.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildBody(string $model, array $messages, array $options): array
    {
        $model = $model !== '' ? $model : $this->defaultModel;
        $body  = [
            'model'    => $model,
            'messages' => $messages,
        ];

        // Pass through supported options
        foreach (['temperature', 'max_tokens', 'top_p', 'stop', 'frequency_penalty', 'presence_penalty', 'seed', 'response_format'] as $key) {
            if (array_key_exists($key, $options)) {
                $body[$key] = $options[$key];
            }
        }

        return $body;
    }

    /**
     * Parse a single SSE 'data: ...' line into an AiStreamChunk.
     *
     * Returns null when the line is empty, is the [DONE] sentinel, or does
     * not start with 'data: '. Throws nothing — malformed JSON inside the
     * data payload is silently skipped.
     *
     * @internal test helper
     */
    public static function parseSseLine(string $line): ?AiStreamChunk
    {
        $line = trim($line);
        if ($line === '' || $line === 'data: [DONE]') {
            return null;
        }
        if (!str_starts_with($line, 'data: ')) {
            return null;
        }
        $parsed = json_decode(substr($line, 6), true);
        if (!is_array($parsed)) {
            return null;
        }

        $choice       = $parsed['choices'][0] ?? [];
        $delta        = $choice['delta'] ?? [];
        $finishReason = $choice['finish_reason'] ?? null;
        $usage        = $parsed['usage'] ?? [];

        return new AiStreamChunk(
            delta:        (string) ($delta['content'] ?? ''),
            finishReason: $finishReason,
            model:        $parsed['model'] ?? null,
            inputTokens:  isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            outputTokens: isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
        );
    }

    /**
     * SSRF guard — same check as HttpClient::assertSafeUrl(). Refuses
     * requests to private/reserved IPs (cloud metadata, loopback, RFC1918).
     */
    private static function assertSafeUrl(string $url): void
    {
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Refusing non-http(s) URL');
        }
        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            throw new RuntimeException('Invalid URL host');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('Refusing request to a private/reserved address');
            }
        }
    }
}
