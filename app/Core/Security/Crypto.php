<?php
declare(strict_types=1);

namespace App\Core\Security;

use RuntimeException;

/**
 * Authenticated symmetric encryption for secrets at rest (e.g. payment provider
 * API keys). AES-256-GCM keyed by APP_KEY (base64 32 bytes in .env). Output is
 * 'enc:' . base64(iv|tag|ciphertext). Fail-closed: if APP_KEY is missing, both
 * encrypt and decrypt throw, so a secret is never silently written/read as
 * plaintext. Tampering is detected via the GCM tag (decrypt returns null).
 */
final class Crypto
{
    private const MARKER = 'enc:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::MARKER);
    }

    public static function encrypt(string $plain): string
    {
        $key = self::key();
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';
        $ct  = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);
        if ($ct === false) {
            throw new RuntimeException('encryption failed');
        }
        return self::MARKER . base64_encode($iv . $tag . $ct);
    }

    /** Returns the plaintext, or null if the input is malformed/tampered. */
    public static function decrypt(string $payload): ?string
    {
        $key = self::key();
        if (!self::isEncrypted($payload)) {
            return null;
        }
        $raw = base64_decode(substr($payload, strlen(self::MARKER)), true);
        if ($raw === false || strlen($raw) <= self::IV_LEN + self::TAG_LEN) {
            return null;
        }
        $iv  = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ct  = substr($raw, self::IV_LEN + self::TAG_LEN);
        $plain = openssl_decrypt($ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }

    /** The raw 32-byte key from APP_KEY (base64). Throws if absent/invalid (fail-closed). */
    private static function key(): string
    {
        $b64 = (string) (env('APP_KEY') ?? '');
        if ($b64 === '') {
            throw new RuntimeException('APP_KEY is not set — cannot handle secrets');
        }
        $key = base64_decode($b64, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('APP_KEY must be base64 of 32 bytes');
        }
        return $key;
    }
}
