<?php

declare(strict_types=1);

namespace O9\Sdk;

/**
 * O9SDK — minimal, self-contained PHP client for an O9-framework API.
 * Copy this single file into any PHP project (it has no dependency on the
 * framework itself — just cURL) to call an O9-based API server-to-server.
 *
 * Talks the {ok, data, error, meta} envelope every endpoint returns and
 * throws an O9ApiException (carrying the canonical error.code) on failure.
 *
 * Usage:
 *   $api = new O9SDK('https://example.com/api/v1', $accessToken);
 *   $health = $api->get('/health');
 *   try {
 *       $api->post('/push/subscribe', ['endpoint' => $endpoint, 'keys' => $keys]);
 *   } catch (O9ApiException $e) {
 *       if ($e->code === 'unauthorized') { ... }
 *   }
 */
final class O9ApiException extends \RuntimeException
{
    /** @param array<string, mixed>|null $details */
    public function __construct(
        public readonly string $code,
        string $message,
        public readonly int $status,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }
}

final class O9SDK
{
    private string $baseUrl;

    public function __construct(string $baseUrl, private ?string $token = null, private int $timeoutSeconds = 15)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function setToken(?string $token): void
    {
        $this->token = $token;
    }

    /** @param array<string, mixed> $query @return mixed the decoded `data` field */
    public function get(string $path, array $query = []): mixed
    {
        return $this->request('GET', $path, $query);
    }

    /** @param array<string, mixed> $body @return mixed */
    public function post(string $path, array $body = []): mixed
    {
        return $this->request('POST', $path, [], $body);
    }

    /** @param array<string, mixed> $body @return mixed */
    public function put(string $path, array $body = []): mixed
    {
        return $this->request('PUT', $path, [], $body);
    }

    public function delete(string $path): mixed
    {
        return $this->request('DELETE', $path);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null): mixed
    {
        $url = $this->baseUrl . (str_starts_with($path, '/') ? $path : '/' . $path);
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $headers = ['Accept: application/json'];
        if ($this->token !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $opts[CURLOPT_POSTFIELDS] = $encoded === false ? '{}' : $encoded;
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new O9ApiException('transport_error', $err !== '' ? $err : 'Request failed.', 0);
        }

        $envelope = json_decode((string) $raw, true);
        if (!is_array($envelope) || !array_key_exists('ok', $envelope)) {
            throw new O9ApiException('bad_response', 'The server returned a non-envelope response.', $status);
        }
        if ($envelope['ok'] !== true) {
            $error = is_array($envelope['error'] ?? null) ? $envelope['error'] : [];
            throw new O9ApiException(
                (string) ($error['code'] ?? 'unknown_error'),
                (string) ($error['message'] ?? 'Request failed.'),
                $status,
                is_array($error['details'] ?? null) ? $error['details'] : null,
            );
        }
        return $envelope['data'] ?? null;
    }
}
