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

    // A queue job reserved longer than this (seconds) is treated as the orphan
    // of a crashed worker and becomes claimable again (Core\Queue::reserve).
    // Must exceed the longest job's runtime or a slow job can double-run.
    // Buried jobs (attempts exhausted) are never reclaimed.
    'queue_retry_after' => (int) env('QUEUE_RETRY_AFTER', 3600),
];
