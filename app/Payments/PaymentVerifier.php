<?php
declare(strict_types=1);

namespace App\Payments;

use App\Payments\Http\CurlHttpClient;
use App\Payments\Http\HttpClient;

/**
 * Operator diagnostic: makes a minimal authenticated READ call per external
 * provider against its real API and reports whether the configured
 * credentials work. Read-only — never charges or writes.
 *
 * The framework ships no live providers (SandboxGateway needs no
 * credentials to verify), so probes are registered by apps that add real
 * gateways, via registerProbe() — the same extend()-style pattern used by
 * PaymentGatewayFactory:
 *
 *   PaymentVerifier::registerProbe('stripe', function (HttpClient $http): array {
 *       $resp = $http->get('https://api.stripe.com/v1/balance', [], ['Authorization: Bearer ' . $secretKey]);
 *       return ['ok' => (int) ($resp['_status'] ?? 0) === 200, 'detail' => 'HTTP ' . ($resp['_status'] ?? 0)];
 *   });
 */
final class PaymentVerifier
{
    /** @var array<string, callable(HttpClient): array{ok: bool, detail: string}> */
    private static array $probes = [];

    public function __construct(private readonly HttpClient $http = new CurlHttpClient())
    {
    }

    /** @param callable(HttpClient): array{ok: bool, detail: string} $probe */
    public static function registerProbe(string $provider, callable $probe): void
    {
        self::$probes[$provider] = $probe;
    }

    /** @internal test reset */
    public static function reset(): void
    {
        self::$probes = [];
    }

    /** @return array{provider: string, configured: bool, ok: ?bool, detail: string} */
    public function verify(string $provider): array
    {
        if (!isset(self::$probes[$provider])) {
            return $this->result($provider, false, null, 'no probe registered for this provider');
        }
        $outcome = (self::$probes[$provider])($this->http);
        return $this->result($provider, true, $outcome['ok'], $outcome['detail']);
    }

    /** @return list<array{provider: string, configured: bool, ok: ?bool, detail: string}> */
    public function verifyAll(): array
    {
        return array_map(fn (string $p): array => $this->verify($p), array_keys(self::$probes));
    }

    /** @return array{provider: string, configured: bool, ok: ?bool, detail: string} */
    private function result(string $provider, bool $configured, ?bool $ok, string $detail): array
    {
        return ['provider' => $provider, 'configured' => $configured, 'ok' => $ok, 'detail' => $detail];
    }
}
