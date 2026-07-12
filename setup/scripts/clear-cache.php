#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Flushes the configured cache store. Equivalent to
 * `php setup/bin/console cache:clear`, kept as its own script for
 * deploy hooks / shell aliases that shouldn't have to know the console
 * kernel's command name.
 *
 *   php setup/scripts/clear-cache.php
 */

define('BASE_PATH', dirname(__DIR__, 2));

if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
} else {
    require BASE_PATH . '/app/Core/Autoloader.php';
    App\Core\Autoloader::register();
    require BASE_PATH . '/app/Core/helpers.php';
}
require BASE_PATH . '/config/env.php';

exit((new App\Console\Commands\CacheClearCommand())->run([]));
