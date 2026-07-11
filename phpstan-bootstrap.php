<?php
declare(strict_types=1);

// Constants normally defined by public/index.php; phpstan analyses files in
// isolation so they must exist before any app code is parsed.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}
