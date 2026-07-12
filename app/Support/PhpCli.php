<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Resolve the PHP **CLI** binary for spawning background scripts/console commands.
 *
 * Under PHP-FPM, PHP_BINARY is the php-fpm binary — handing it a script just
 * prints php-fpm's usage and exits, so any detached `php …` spawned from a
 * web request silently no-ops. This resolves the real CLI binary instead.
 * Override with config('worker.php_binary') / env PHP_CLI_BINARY.
 */
final class PhpCli
{
    public static function path(): string
    {
        $cands = [];
        $cfg = (string) config('worker.php_binary', '');
        if ($cfg !== '') {
            $cands[] = $cfg;
        }
        if (!str_contains(PHP_BINARY, 'fpm')) {
            $cands[] = PHP_BINARY; // already CLI
        }
        $cands[] = (string) preg_replace('#/s?bin/php-fpm[0-9.]*$#', '/bin/php', PHP_BINARY); // fpm -> cli sibling
        $cands[] = dirname(PHP_BINARY) . '/php';
        $cands[] = '/usr/bin/php';
        foreach ($cands as $c) {
            if (@is_executable($c)) {
                return $c;
            }
        }
        return 'php'; // last resort: rely on PATH
    }
}
