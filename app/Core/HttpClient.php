<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Zero-dependency cURL HTTP client (Guzzle-shaped, no dependency).
 *
 * Implements the small subset of the Guzzle API most projects actually use
 * so call sites read the same way:
 *   $http = new HttpClient(['timeout' => 15, 'http_errors' => false]);
 *   $res  = $http->get($url, ['query' => [...], 'headers' => [...]]);
 *   $body = $res->getBody()->getContents();
 *
 * Supported per-request options: query, headers, json, form_params, body,
 * timeout, connect_timeout, http_errors, verify, retries.
 */
final class HttpClient
{
    /** @var array<string, mixed> */
    private array $defaults;

    /**
     * Shared cURL handle: pools DNS cache, TLS sessions and (where supported)
     * live connections across every request made through this instance, so a
     * burst of calls to the same host reuses one keep-alive connection
     * instead of re-handshaking each time.
     */
    private ?\CurlShareHandle $share = null;

    /** @param array<string, mixed> $defaults */
    public function __construct(array $defaults = [])
    {
        $this->defaults = $defaults;
        $sh = curl_share_init();
        curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
        curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
        if (defined('CURL_LOCK_DATA_CONNECT')) {
            curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
        }
        $this->share = $sh;
    }

    /** @param array<string, mixed> $options */
    public function get(string $url, array $options = []): HttpClientResponse
    {
        return $this->request('GET', $url, $options);
    }

    /** @param array<string, mixed> $options */
    public function post(string $url, array $options = []): HttpClientResponse
    {
        return $this->request('POST', $url, $options);
    }

    /** @param array<string, mixed> $options */
    public function put(string $url, array $options = []): HttpClientResponse
    {
        return $this->request('PUT', $url, $options);
    }

    /** @param array<string, mixed> $options */
    public function delete(string $url, array $options = []): HttpClientResponse
    {
        return $this->request('DELETE', $url, $options);
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): HttpClientResponse
    {
        if ($url === '') {
            throw new \InvalidArgumentException('HttpClient: URL must not be empty');
        }
        $method = strtoupper($method);
        if ($method === '') {
            throw new \InvalidArgumentException('HttpClient: method must not be empty');
        }

        $opt = array_merge($this->defaults, $options);

        if (!empty($opt['query']) && is_array($opt['query'])) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($opt['query']);
        }

        $headers = [];
        foreach (($opt['headers'] ?? []) as $k => $v) {
            $headers[] = $k . ': ' . $v;
        }

        $body = null;
        if (isset($opt['json'])) {
            $encoded = json_encode($opt['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new \RuntimeException('HttpClient: failed to JSON-encode the request body');
            }
            $body = $encoded;
            $headers[] = 'Content-Type: application/json';
        } elseif (isset($opt['form_params']) && is_array($opt['form_params'])) {
            $body = http_build_query($opt['form_params']);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif (isset($opt['body'])) {
            $body = (string) $opt['body'];
        }

        // Guzzle accepts float (sub-second) timeouts; use the *_MS options so a
        // value like 0.5 isn't truncated to 0 (which cURL treats as "no timeout").
        $timeoutMs = (int) round(((float) ($opt['timeout'] ?? 30)) * 1000);
        $connectMs = (int) round(((float) ($opt['connect_timeout'] ?? 10)) * 1000);

        // SSRF guard: only http(s), and refuse hosts that resolve to a private/reserved
        // address (cloud metadata 169.254.169.254, loopback, RFC1918, etc.).
        self::assertSafeUrl($url);

        // Retry only idempotent methods on transient failures, with capped
        // exponential backoff + jitter. POST/PUT/DELETE are never retried (a
        // half-completed write must not be replayed). Override via opt['retries'].
        $isIdempotent = in_array($method, ['GET', 'HEAD'], true);
        $maxRetries   = (int) ($opt['retries'] ?? ($isIdempotent ? 2 : 0));
        if (!$isIdempotent) {
            $maxRetries = 0;
        }
        $httpErrors = $opt['http_errors'] ?? true;

        $attempt = 0;
        while (true) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL             => $url,
                CURLOPT_CUSTOMREQUEST   => $method,
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_HEADER          => false,
                CURLOPT_FOLLOWLOCATION  => true,
                CURLOPT_MAXREDIRS       => 3,
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_TIMEOUT_MS        => $timeoutMs,
                CURLOPT_CONNECTTIMEOUT_MS => $connectMs,
                CURLOPT_SSL_VERIFYPEER => array_key_exists('verify', $opt) ? (bool) $opt['verify'] : true,
                CURLOPT_SSL_VERIFYHOST => (array_key_exists('verify', $opt) && !$opt['verify']) ? 0 : 2,
            ]);
            if ($this->share !== null) {
                curl_setopt($ch, CURLOPT_SHARE, $this->share);
            }
            if (isset($opt['verify']) && is_string($opt['verify']) && $opt['verify'] !== '') {
                curl_setopt($ch, CURLOPT_CAINFO, $opt['verify']);
            }
            if ($headers !== []) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            if ($method === 'HEAD') {
                curl_setopt($ch, CURLOPT_NOBODY, true);
            }

            $raw    = curl_exec($ch);
            $errno  = curl_errno($ch);
            $error  = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $networkFailed = ($errno !== 0 || $raw === false);
            $transient = $networkFailed || in_array($status, [429, 502, 503, 504], true);

            if ($transient && $attempt < $maxRetries) {
                // backoff: 200ms, 400ms, ... capped at 2s, plus up to 100ms jitter
                $backoffMs = min(2000, 200 * (2 ** $attempt)) + random_int(0, 100);
                usleep($backoffMs * 1000);
                $attempt++;
                continue;
            }

            if ($networkFailed) {
                throw new \RuntimeException('HTTP request failed: ' . $error, $errno);
            }

            if ($httpErrors && $status >= 400) {
                throw new \RuntimeException('HTTP ' . $status . ' for ' . $method . ' ' . $url, $status);
            }

            return new HttpClientResponse($status, (string) $raw);
        }
    }

    /**
     * SSRF protection: allow only http/https and reject any host that
     * resolves to a private, reserved, loopback or link-local address
     * (blocks cloud metadata 169.254.169.254, 127.0.0.1, 10.x, 192.168.x,
     * etc.). Public hosts pass unchanged.
     */
    private static function assertSafeUrl(string $url): void
    {
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Refusing non-http(s) URL');
        }
        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            throw new \RuntimeException('Invalid URL host');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \RuntimeException('Refusing request to a private/reserved address');
            }
        }
    }
}
