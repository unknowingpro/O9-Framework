<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Immutable wrapper over PHP super-globals + a parsed JSON body.
 *
 * Two pieces of per-request state are attached after construction by the
 * framework itself (never by app code): route placeholders (set by the Router
 * on match) and the authenticated actor (set by auth middleware).
 */
final class Request
{
    /** @var array<string, mixed> */
    private array $get;
    /** @var array<string, mixed> */
    private array $post;
    /** @var array<string, mixed> */
    private array $files;
    /** @var array<string, mixed> */
    private array $cookies;
    /** @var array<string, mixed> */
    private array $server;
    /** @var array<string, string> */
    private array $headers;
    /** @var array<string, mixed> */
    private array $jsonBody;
    private string $rawBody;

    /** @var array<string, string> Route placeholders captured by the Router. */
    private array $params = [];

    /** @var array<string, mixed>|null Authenticated actor (user/admin/api-key row). */
    private ?array $actor = null;

    public function __construct()
    {
        $this->get     = $_GET;
        $this->post    = $_POST;
        $this->files   = $_FILES;
        $this->cookies = $_COOKIE;
        $this->server  = $_SERVER;
        $this->headers = $this->loadHeaders();
        $this->rawBody = (string) file_get_contents('php://input');
        $this->jsonBody = $this->parseJsonBody();
    }

    /**
     * Returns a single uploaded file entry ($_FILES[$key]) when present and
     * uploaded without error, else null.
     *
     * @return array<string, mixed>|null
     */
    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        return $f;
    }

    /** @return array<string, string> */
    private function loadHeaders(): array
    {
        $headers = [];
        foreach ($this->server as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = $value;
            }
        }
        if (isset($this->server['CONTENT_TYPE'])) {
            $headers['content-type'] = $this->server['CONTENT_TYPE'];
        }
        if (isset($this->server['CONTENT_LENGTH'])) {
            $headers['content-length'] = $this->server['CONTENT_LENGTH'];
        }
        return $headers;
    }

    /** @return array<string, mixed> */
    private function parseJsonBody(): array
    {
        $ct = $this->header('content-type', '');
        if ($this->rawBody === '' || !str_contains($ct, 'json')) {
            return [];
        }
        $decoded = json_decode($this->rawBody, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function uri(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $q = strpos($uri, '?');
        return $q === false ? $uri : substr($uri, 0, $q);
    }

    public function path(): string
    {
        return '/' . trim($this->uri(), '/');
    }

    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        // Only honour X-Forwarded-For if the direct peer is a trusted
        // reverse proxy (configured in app.trusted_proxies). Otherwise an
        // attacker can spoof the header to bypass per-IP rate limits, fake
        // their geo, or pollute audit logs.
        $trusted = (array) config('app.trusted_proxies', []);
        if ($trusted === [] || !self::ipMatchesAny($remote, $trusted)) {
            return $remote;
        }
        // The direct peer is a trusted proxy. Cloudflare sends the authoritative
        // client IP in CF-Connecting-IP — prefer it (single value, no chain to
        // validate), then fall back to the X-Forwarded-For chain.
        $cf = (string) ($this->server['HTTP_CF_CONNECTING_IP'] ?? '');
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) !== false) {
            return $cf;
        }
        $xff = (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            // XFF is a comma-separated chain; the client is left-most, proxies
            // append on the right. Accept it only if every hop after the client
            // is itself trusted (supports exact IPs and CIDR ranges).
            $hops = array_map('trim', explode(',', $xff));
            $client = (string) array_shift($hops);
            foreach ($hops as $hop) {
                if (!self::ipMatchesAny($hop, $trusted)) {
                    return $remote;
                }
            }
            if (filter_var($client, FILTER_VALIDATE_IP) !== false) {
                return $client;
            }
        }
        return $remote;
    }

    /** @param array<int,mixed> $list  True if $ip matches any exact IP or CIDR block in $list. */
    public static function ipMatchesAny(string $ip, array $list): bool
    {
        foreach ($list as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') { continue; }
            if (str_contains($entry, '/')) {
                if (self::ipInCidr($ip, $entry)) { return true; }
            } elseif ($ip === $entry) {
                return true;
            }
        }
        return false;
    }

    /** True if $ip falls within the CIDR block $cidr (IPv4 or IPv6). */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) { return false; }
        [$subnet, $bitsStr] = $parts;
        $bits = (int) $bitsStr;
        $ipP  = @inet_pton($ip);
        $subP = @inet_pton($subnet);
        if ($ipP === false || $subP === false || strlen($ipP) !== strlen($subP)) {
            return false;
        }
        $maxBits = strlen($ipP) * 8;
        if ($bits < 0 || $bits > $maxBits) { return false; }
        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;
        if ($bytes > 0 && substr($ipP, 0, $bytes) !== substr($subP, 0, $bytes)) {
            return false;
        }
        if ($rem === 0) { return true; }
        $mask = 0xff << (8 - $rem) & 0xff;
        return (ord($ipP[$bytes]) & $mask) === (ord($subP[$bytes]) & $mask);
    }

    public function header(string $name, string $default = ''): string
    {
        return (string) ($this->headers[strtolower($name)] ?? $default);
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /** @return array<string,string> all request headers (keys lowercased). */
    public function headers(): array
    {
        return $this->headers;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->jsonBody)) {
            return $this->jsonBody[$key];
        }
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        if (array_key_exists($key, $this->get)) {
            return $this->get[$key];
        }
        return $default;
    }

    /**
     * Like input(), but reads the request BODY only (JSON then POST) — never the query string.
     * Use for values that must not be satisfiable via the URL, e.g. the CSRF token.
     */
    public function bodyParam(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->jsonBody)) {
            return $this->jsonBody[$key];
        }
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        return $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge($this->get, $this->post, $this->jsonBody);
    }

    /**
     * @internal The Router attaches the matched {placeholder} values on dispatch.
     * @param array<string, string> $params
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /** A route placeholder captured by the Router, e.g. param('id'). */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /** @return array<string, string> all captured route placeholders. */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * @internal Auth middleware attaches the authenticated actor.
     * @param array<string, mixed>|null $actor
     */
    public function setActor(?array $actor): void
    {
        $this->actor = $actor;
    }

    /**
     * The authenticated actor for this request (user/admin/api-key row), or null.
     *
     * @return array<string, mixed>|null
     */
    public function actor(): ?array
    {
        return $this->actor;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization', '');
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /** Native-client metadata headers (set by the mobile apps). */
    public function appVersion(): string
    {
        return trim($this->header('x-app-version', ''));
    }

    public function platform(): string
    {
        return strtolower(trim($this->header('x-platform', '')));
    }

    public function deviceId(): string
    {
        return trim($this->header('x-device-id', ''));
    }

    /** Human label for the device list: X-Device-Name, else a trimmed User-Agent. */
    public function deviceLabel(): string
    {
        $name = trim($this->header('x-device-name', ''));
        return mb_substr($name !== '' ? $name : trim($this->header('user-agent', '')), 0, 120);
    }

    public function wantsJson(): bool
    {
        $accept = $this->header('accept', '');
        $ct     = $this->header('content-type', '');
        return str_contains($accept, 'json') || str_contains($ct, 'json') || str_starts_with($this->path(), '/api/');
    }

    public function cookie(string $key, string $default = ''): string
    {
        return (string) ($this->cookies[$key] ?? $default);
    }
}
