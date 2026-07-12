<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Dependency-free daily-file logger. Writes JSON-lines to
 * storage/logs/app-YYYY-MM-DD.log. Levels follow PSR-3 names.
 *
 * The flat file is the primary, always-on sink (fast, works before the DB is
 * up and in CLI). ERROR/WARNING lines — and anything sent via {@see self::event()}
 * — are also handed to an optional persist hook (persistUsing()) so an app
 * can mirror them into a queryable DB table; that hook is fire-and-forget
 * from the logger's point of view; the app decides how to guard it. A
 * `channel` is derived from the message's dotted prefix ('queue.job.buried'
 * -> "queue") unless given, so existing call sites get channels for free.
 */
final class Logger
{
    /** Lazily-built once per request so we don't re-read php://input per line. */
    private static ?Request $request = null;

    /** Whether the log dir has been ensured this process — avoids an is_dir() stat per line. */
    private static bool $dirReady = false;

    /** @var (callable(string, array<string, mixed>): void)|null */
    private static $persister = null;

    /**
     * Register a sink for ERROR/WARNING/event() lines beyond the flat file
     * (e.g. a queryable DB log table). Receives the channel and the full
     * log entry array.
     *
     * @param (callable(string, array<string, mixed>): void)|null $fn
     */
    public static function persistUsing(?callable $fn): void
    {
        self::$persister = $fn;
    }

    /** @internal test reset */
    public static function reset(): void
    {
        self::$request = null;
        self::$dirReady = false;
        self::$persister = null;
    }

    private static function clientIp(): string
    {
        self::$request ??= new Request();
        return self::$request->ip();
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = [], ?string $channel = null): void
    {
        self::write('ERROR', $message, $context, $channel, true);
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $message, array $context = [], ?string $channel = null): void
    {
        self::write('WARNING', $message, $context, $channel, true);
    }

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = [], ?string $channel = null): void
    {
        self::write('INFO', $message, $context, $channel, false);
    }

    /**
     * A notable INFO event that SHOULD persist via the registered hook (e.g. security/admin events).
     *
     * @param array<string, mixed> $context
     */
    public static function event(string $channel, string $message, array $context = []): void
    {
        self::write('INFO', $message, $context, $channel, true);
    }

    /** @param array<string, mixed> $context */
    public static function exception(\Throwable $e, array $context = [], ?string $channel = 'error'): void
    {
        self::write('ERROR', $e->getMessage(), $context + [
            'exception' => $e::class,
            'file'      => $e->getFile() . ':' . $e->getLine(),
            'trace'     => explode("\n", $e->getTraceAsString()),
        ], $channel, true);
    }

    /** Derive a channel from a dotted message prefix ('mail.send_failed' -> "mail"). */
    private static function channelFor(string $message): string
    {
        $i = strpos($message, '.');
        $c = $i > 0 ? substr($message, 0, $i) : '';
        return ($c !== '' && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $c)) ? $c : 'app';
    }

    /** @param array<string, mixed> $context */
    private static function write(string $level, string $message, array $context, ?string $channel, bool $persist): void
    {
        $dir = base_path('storage/logs');
        if (!self::$dirReady) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            self::$dirReady = true;
        }
        $entry = [
            'ts'      => date('c'),
            'level'   => $level,
            'msg'     => $message,
            'method'  => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            // Redact API keys / tokens from the query string before logging — logs
            // get backed up, shipped, and read by operators. Without this every
            // log line carrying a query-string credential is a working leak.
            'uri'     => preg_replace(
                '/([?&](?:_k|api_key|apikey|access[_-]?token|token|secret|password)=)[^&#\s]*/i',
                '$1REDACTED',
                (string) ($_SERVER['REQUEST_URI'] ?? '')
            ),
            // Via Request::ip() so the trusted-proxy allowlist is honoured — a bare
            // HTTP_X_FORWARDED_FOR here would let any client spoof log IPs.
            'ip'      => self::clientIp(),
        ] + (ApiContext::active() ? ['rid' => ApiContext::id()] : []) + $context;

        // Skip the write entirely when the target isn't writable (e.g. a root-owned
        // log file FPM can't append to) so no warning is ever generated — defence in
        // depth alongside the global error handler.
        $file = $dir . '/app-' . date('Y-m-d') . '.log';
        if (is_file($file) ? is_writable($file) : is_writable($dir)) {
            @file_put_contents(
                $file,
                json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
                FILE_APPEND | LOCK_EX
            );
        }

        if ($persist && self::$persister !== null) {
            (self::$persister)($channel ?? self::channelFor($message), $entry);
        }
    }
}
