<?php
declare(strict_types=1);

namespace App\Core\Security;

/**
 * RFC 6238 TOTP — 6-digit codes derived from an HMAC-SHA1 of a shared
 * secret + the 30-second time counter. Used for 2FA enrolment + login.
 *
 * Deliberately tiny + dependency-free. Constant-time compare prevents
 * timing side-channels on the verify path.
 */
final class Totp
{
    /** Verify a user-entered 6-digit code against a base32-encoded secret. */
    public static function verify(string $base32Secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if ($code === null || strlen($code) !== 6) return false;
        $secret = self::base32Decode($base32Secret);
        if ($secret === '') return false;
        $t = (int) floor(time() / 30);
        // Accept codes within ±$window 30-second buckets to tolerate clock drift.
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::compute($secret, $t + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /** Standard otpauth:// URI for QR-code apps (Google Authenticator, 1Password, etc.). */
    public static function provisioningUri(string $issuer, string $accountName, string $base32Secret): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($accountName),
            rawurlencode($base32Secret),
            rawurlencode($issuer),
        );
    }

    private static function compute(string $secret, int $counter): string
    {
        // 8-byte big-endian counter.
        $bin = pack('N*', 0, $counter);
        $hmac = hash_hmac('sha1', $bin, $secret, true);
        $offset = ord($hmac[19]) & 0x0F;
        $val = (ord($hmac[$offset]) & 0x7F) << 24
             | (ord($hmac[$offset + 1]) & 0xFF) << 16
             | (ord($hmac[$offset + 2]) & 0xFF) << 8
             |  ord($hmac[$offset + 3]) & 0xFF;
        return str_pad((string) ($val % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** Strict RFC 4648 base32 decode — uppercase, A-Z + 2-7. */
    private static function base32Decode(string $s): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s) ?? '');
        if ($s === '') return '';
        $bits = '';
        foreach (str_split($s) as $c) {
            $bits .= str_pad(decbin((int) strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }
        return $out;
    }
}
