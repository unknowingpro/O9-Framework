<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Job — a queued background unit of work, identified by its class name.
 * handle() receives the payload that was pushed onto the queue.
 */
interface Job
{
    /** @param array<string,mixed> $payload */
    public function handle(array $payload): void;
}
