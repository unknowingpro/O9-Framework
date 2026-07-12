<?php
declare(strict_types=1);

namespace Tests\Core\Security;

use App\Core\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    public function testEncryptDecryptRoundTrip(): void
    {
        $secret = 'sk_live_4242424242424242 — پرداخت';
        $payload = Crypto::encrypt($secret);
        $this->assertTrue(Crypto::isEncrypted($payload));
        $this->assertStringStartsWith('enc:', $payload);
        $this->assertNotSame($secret, $payload);
        $this->assertSame($secret, Crypto::decrypt($payload));
    }

    public function testEachEncryptionUsesAFreshIv(): void
    {
        $a = Crypto::encrypt('same-plaintext');
        $b = Crypto::encrypt('same-plaintext');
        $this->assertNotSame($a, $b);
        $this->assertSame('same-plaintext', Crypto::decrypt($a));
        $this->assertSame('same-plaintext', Crypto::decrypt($b));
    }

    public function testTamperedCiphertextDecryptsToNull(): void
    {
        $payload = Crypto::encrypt('top secret');
        $raw = (string) base64_decode(substr($payload, 4), true);
        // Flip one bit in the last ciphertext byte — the GCM tag must catch it.
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);
        $this->assertNull(Crypto::decrypt('enc:' . base64_encode($raw)));
    }

    public function testMalformedInputDecryptsToNull(): void
    {
        $this->assertNull(Crypto::decrypt('plaintext-without-marker'));
        $this->assertNull(Crypto::decrypt('enc:not-valid-base64!!!'));
        $this->assertNull(Crypto::decrypt('enc:' . base64_encode('short')));
    }

    public function testFailsClosedWithoutAppKey(): void
    {
        $saved = (string) getenv('APP_KEY');
        putenv('APP_KEY');
        unset($_ENV['APP_KEY']);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('APP_KEY is not set');
            Crypto::encrypt('secret');
        } finally {
            putenv('APP_KEY=' . $saved);
            $_ENV['APP_KEY'] = $saved;
        }
    }

    public function testFailsClosedWithMalformedAppKey(): void
    {
        $saved = (string) getenv('APP_KEY');
        putenv('APP_KEY=' . base64_encode('too-short'));
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('APP_KEY must be base64 of 32 bytes');
            Crypto::encrypt('secret');
        } finally {
            putenv('APP_KEY=' . $saved);
        }
    }
}
