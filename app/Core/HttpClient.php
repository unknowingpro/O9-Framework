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
            // SSRF guard: validate + resolve the URL, then follow redirects
            // manually so EVERY hop is re-checked and pinned to the exact IP we
            // approved (curl's own FOLLOWLOCATION would validate only hop 0).
            [$raw, $errno, $error, $status] = $this->send($method, $url, $headers, $body, $opt, $timeoutMs, $connectMs);

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
     * Perform one logical request, following up to 3 redirects by hand. Each
     * hop is re-validated by assertSafeUrl() and pinned via CURLOPT_RESOLVE to
     * the IP that check approved — so a 302 to an internal address is rejected,
     * and DNS can't be rebound between the check and the connect.
     *
     * @param list<string>         $headers
     * @param array<string, mixed> $opt
     * @return array{0: string|bool, 1: int, 2: string, 3: int} [body, errno, error, status]
     */
    private function send(string $method, string $url, array $headers, ?string $body, array $opt, int $timeoutMs, int $connectMs): array
    {
        $maxHops = 3;
        for ($hop = 0; ; $hop++) {
            if ($url === '' || $method === '') {
                throw new \RuntimeException('HttpClient: empty redirect target or method');
            }
            $pin = self::assertSafeUrl($url);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL             => $url,
                CURLOPT_CUSTOMREQUEST   => $method,
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_HEADER          => false,
                // We follow redirects ourselves (see the loop) so each hop is
                // re-validated; curl must not chase them behind our back.
                CURLOPT_FOLLOWLOCATION  => false,
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_TIMEOUT_MS        => $timeoutMs,
                CURLOPT_CONNECTTIMEOUT_MS => $connectMs,
                CURLOPT_SSL_VERIFYPEER => array_key_exists('verify', $opt) ? (bool) $opt['verify'] : true,
                CURLOPT_SSL_VERIFYHOST => (array_key_exists('verify', $opt) && !$opt['verify']) ? 0 : 2,
                // Pin the approved IP for this host:port so the connect can't be
                // rebound to a different (internal) address after validation.
                CURLOPT_RESOLVE         => [$pin['host'] . ':' . $pin['port'] . ':' . $pin['ip']],
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

            $raw      = curl_exec($ch);
            $errno    = curl_errno($ch);
            $error    = curl_error($ch);
            $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);

            // A 3xx with a Location we can resolve to an absolute URL: re-enter
            // the loop (re-validating the new target) until we run out of hops.
            if ($errno === 0 && $status >= 300 && $status < 400 && $location !== '' && $hop < $maxHops) {
                $url = $location;
                continue;
            }
            return [$raw, $errno, $error, $status];
        }
    }

    /**
     * SSRF protection: allow only http/https and reject any host that resolves
     * to a private, reserved, loopback or link-local address (blocks cloud
     * metadata 169.254.169.254, 127.0.0.1, 10.x, 192.168.x, ::1, fc00::/7,
     * etc.). Resolves BOTH IPv4 and IPv6 and fails CLOSED when a host resolves
     * to nothing — an unresolvable or AAAA-only host must not slip past.
     *
     * Returns the approved {host, port, ip} so the caller can pin the
     * connection to the exact address we validated (closing the DNS-rebinding
     * gap between check and connect).
     *
     * @return array{host: string, port: int, ip: string}
     */
    private static function assertSafeUrl(string $url): array
    {
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Refusing non-http(s) URL');
        }
        $host = (string) ($parts['host'] ?? '');
        // parse_url keeps the brackets on an IPv6 literal host ([::1]) — strip
        // them so FILTER_VALIDATE_IP and the resolver see the bare address.
        $bareHost = (str_starts_with($host, '[') && str_ends_with($host, ']'))
            ? substr($host, 1, -1)
            : $host;
        if ($bareHost === '') {
            throw new \RuntimeException('Invalid URL host');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $ips = self::resolveHost($bareHost);
        if ($ips === []) {
            // Fail closed: a host we cannot resolve is not a host we can vouch for.
            throw new \RuntimeException('Refusing request: could not resolve host ' . $bareHost);
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \RuntimeException('Refusing request to a private/reserved address');
            }
        }
        // Pin the first approved address (all were checked; curl uses one).
        return ['host' => $bareHost, 'port' => $port, 'ip' => $ips[0]];
    }

    /**
     * Resolve a host to every IP (v4 + v6) it points at. An IP literal returns
     * itself. Returns [] when nothing resolves so the caller can fail closed.
     *
     * @return list<string>
     */
    private static function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        foreach ($records as $r) {
            if (isset($r['ip']))   { $ips[] = (string) $r['ip']; }   // A
            if (isset($r['ipv6'])) { $ips[] = (string) $r['ipv6']; } // AAAA
        }
        if ($ips === []) {
            // dns_get_record can miss records some resolvers still answer for;
            // fall back to the IPv4-only lookup before giving up.
            $ips = gethostbynamel($host) ?: [];
        }
        return array_values(array_unique($ips));
    }
}
