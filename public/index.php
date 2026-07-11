<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
} else {
    require BASE_PATH . '/app/Core/Autoloader.php';
    App\Core\Autoloader::register();
    require BASE_PATH . '/app/Core/helpers.php';
}
require BASE_PATH . '/config/env.php';

App\Core\App::getInstance()->run();
