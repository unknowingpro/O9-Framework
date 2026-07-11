<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

// Composer autoloader when present, framework fallback otherwise — the same
// dual-mode contract public/index.php honors.
if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
} else {
    require BASE_PATH . '/app/Core/Autoloader.php';
    \App\Core\Autoloader::register();
    require BASE_PATH . '/app/Core/helpers.php';
}
