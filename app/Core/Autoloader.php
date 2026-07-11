<?php
declare(strict_types=1);

namespace App\Core;

/**
 * PSR-4 fallback autoloader so the project can run without Composer.
 * Maps the `App\` namespace to the `app/` directory.
 */
final class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register([self::class, 'load']);
    }

    public static function load(string $class): void
    {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
        $path = BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . $relativePath;
        if (is_file($path)) {
            require $path;
        }
    }
}
