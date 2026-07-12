<?php
declare(strict_types=1);

namespace App\Controllers\Bot;

use App\Core\Metrics;

/**
 * Bot admin commands (sample). Not an HTTP-routed controller — its
 * dispatch() is called directly from WebhookController when an incoming
 * message looks like a command from a configured admin chat id.
 */
final class AdminBotController
{
    /** @var (callable(): list<int>)|null */
    private static $adminsHook = null;

    /**
     * Override the admin id source (tests, or an app-side DB-backed list).
     *
     * @param (callable(): list<int>)|null $fn
     */
    public static function adminsUsing(?callable $fn): void
    {
        self::$adminsHook = $fn;
    }

    /** @internal test reset */
    public static function reset(): void
    {
        self::$adminsHook = null;
    }

    /** @return list<int> admin Telegram user ids allowed to run these commands. */
    private function admins(): array
    {
        if (self::$adminsHook !== null) {
            return (self::$adminsHook)();
        }
        return array_values(array_filter(array_map(
            'intval',
            explode(',', (string) config('bot.admin_ids', ''))
        )));
    }

    /** Handle a "/command args" message; returns the reply text, or null to say nothing. */
    public function dispatch(int $fromId, string $text): ?string
    {
        if (!in_array($fromId, $this->admins(), true)) {
            return null;
        }

        [$command] = explode(' ', trim($text), 2) + [null, null];
        return match ($command) {
            '/stats' => $this->stats(),
            '/ping'  => 'pong',
            default  => null,
        };
    }

    private function stats(): string
    {
        $samples = Metrics::collect();
        $up = 0;
        foreach ($samples as $s) {
            if ($s['name'] === 'o9_worker_up' && (float) $s['value'] === 1.0) {
                $up++;
            }
        }
        return "Workers up: {$up}";
    }
}
