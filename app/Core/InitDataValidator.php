<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Validates Telegram WebApp `initData` (the signed query-string payload a
 * Telegram Mini App receives from the client). Per Telegram's spec: the
 * `hash` field is an HMAC-SHA256 of every other field (sorted, "key=value"
 * joined by "\n"), keyed by HMAC-SHA256("WebAppData", bot_token).
 *
 * @see https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 */
final class InitDataValidator
{
    /**
     * @param string $initData raw query string from the client (Telegram.WebApp.initData)
     * @param string $botToken the bot token used to derive the signing key
     * @param int $maxAgeSeconds reject payloads older than this (replay protection); 0 disables the check
     */
    public static function validate(string $initData, string $botToken, int $maxAgeSeconds = 86400): bool
    {
        if ($initData === '' || $botToken === '') {
            return false;
        }
        parse_str($initData, $raw);
        if (!isset($raw['hash']) || !is_string($raw['hash'])) {
            return false;
        }
        $hash = $raw['hash'];
        unset($raw['hash']);

        // Real Telegram initData is a flat key=value payload — any nested/array
        // value here is malformed or adversarial input, never valid.
        $params = [];
        foreach ($raw as $k => $v) {
            if (!is_string($v)) {
                return false;
            }
            $params[(string) $k] = $v;
        }

        if ($maxAgeSeconds > 0) {
            $authDate = (int) ($params['auth_date'] ?? 0);
            if ($authDate <= 0 || (time() - $authDate) > $maxAgeSeconds) {
                return false;
            }
        }

        ksort($params);
        $dataCheckStr = implode("\n", array_map(
            static fn (string $k, string $v): string => "{$k}={$v}",
            array_keys($params),
            $params
        ));

        $secretKey    = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $expectedHash = hash_hmac('sha256', $dataCheckStr, $secretKey);

        return hash_equals($expectedHash, $hash);
    }

    /**
     * Parse initData into its fields WITHOUT verifying the signature. Callers
     * must call validate() first — this is a convenience for reading fields
     * (e.g. `user`) after validation has already passed.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $initData): array
    {
        parse_str($initData, $raw);
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }
}
