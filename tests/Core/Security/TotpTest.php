<?php
declare(strict_types=1);

namespace Tests\Core\Security;

use App\Core\Security\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    private const RFC_SECRET = '12345678901234567890'; // RFC 4226 test secret (raw)

    public function testComputeMatchesRfc4226Vectors(): void
    {
        // Appendix D of RFC 4226: HOTP values for the standard test secret.
        $vectors = [0 => '755224', 1 => '287082', 2 => '359152', 9 => '520489'];
        foreach ($vectors as $counter => $expected) {
            $this->assertSame($expected, $this->compute(self::RFC_SECRET, $counter), "counter $counter");
        }
    }

    public function testVerifyAcceptsCurrentCodeAndToleratesDrift(): void
    {
        $base32 = $this->base32Encode(self::RFC_SECRET);
        $bucket = (int) floor(time() / 30);
        $this->assertTrue(Totp::verify($base32, $this->compute(self::RFC_SECRET, $bucket)));
        // Previous + next buckets pass with the default ±1 window…
        $this->assertTrue(Totp::verify($base32, $this->compute(self::RFC_SECRET, $bucket - 1)));
        $this->assertTrue(Totp::verify($base32, $this->compute(self::RFC_SECRET, $bucket + 1)));
        // …but not with window 0.
        $this->assertFalse(Totp::verify($base32, $this->compute(self::RFC_SECRET, $bucket + 1), 0));
    }

    public function testVerifyToleratesFormattingInUserInput(): void
    {
        $base32 = $this->base32Encode(self::RFC_SECRET);
        $bucket = (int) floor(time() / 30);
        $code = $this->compute(self::RFC_SECRET, $bucket);
        $spaced = substr($code, 0, 3) . ' ' . substr($code, 3); // "123 456" as typed
        $this->assertTrue(Totp::verify($base32, $spaced));
    }

    public function testVerifyRejectsMalformedInput(): void
    {
        $base32 = $this->base32Encode(self::RFC_SECRET);
        $this->assertFalse(Totp::verify($base32, ''));
        $this->assertFalse(Totp::verify($base32, '12345'));
        $this->assertFalse(Totp::verify($base32, 'abcdef'));
        $this->assertFalse(Totp::verify('', '123456'));
    }

    public function testGenerateSecretProducesAValidBase32SecretUsableByVerify(): void
    {
        $secret = Totp::generateSecret();
        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertNotSame($secret, Totp::generateSecret());

        // Round-trip: a code computed for this generated secret must verify —
        // proves it decodes to real usable key material, not just the right shape.
        $m = new \ReflectionMethod(Totp::class, 'base32Decode');
        $raw = (string) $m->invoke(null, $secret);
        $bucket = (int) floor(time() / 30);
        $this->assertTrue(Totp::verify($secret, $this->compute($raw, $bucket)));
    }

    public function testGenerateRecoveryCodesProducesTheRequestedCountOfUniqueCodes(): void
    {
        $codes = Totp::generateRecoveryCodes(10);
        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes), 'every code must be unique');
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $code);
        }

        $this->assertCount(5, Totp::generateRecoveryCodes(5));
        $this->assertNotSame(Totp::generateRecoveryCodes(10), Totp::generateRecoveryCodes(10));
    }

    public function testProvisioningUriFormat(): void
    {
        $uri = Totp::provisioningUri('O9 App', 'user@example.com', 'JBSWY3DPEHPK3PXP');
        $this->assertStringStartsWith('otpauth://totp/O9%20App:user%40example.com?', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=O9%20App', $uri);
        $this->assertStringContainsString('algorithm=SHA1&digits=6&period=30', $uri);
    }

    /** Call the private HOTP core so tests control the counter directly. */
    private function compute(string $rawSecret, int $counter): string
    {
        $m = new \ReflectionMethod(Totp::class, 'compute');
        return (string) $m->invoke(null, $rawSecret, $counter);
    }

    private function base32Encode(string $raw): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($raw) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[(int) bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }
}
