<?php
declare(strict_types=1);

return [
    // Directory for worker heartbeats, PID locks, and single-instance flocks.
    // Relative paths resolve under the project root (see Support\Worker\Heartbeat).
    'run_dir' => env('WORKER_RUN_DIR', 'storage/run'),

    // Absolute path to the PHP CLI binary, when auto-detection (Support\PhpCli)
    // picks the wrong one (e.g. an unusual PHP-FPM install layout).
    'php_binary' => env('PHP_CLI_BINARY', ''),

    // A worker's heartbeat older than this (seconds) is reported as down by
    // Core\Metrics.
    'heartbeat_max_age' => (int) env('WORKER_HEARTBEAT_MAX_AGE', 120),
];
