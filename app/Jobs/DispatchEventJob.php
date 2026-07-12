<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Core\Events;
use App\Core\Job;

/**
 * DispatchEventJob — the async half of Events::dispatchAsync(). Re-dispatches an
 * event synchronously inside the queue worker. Listeners must be registered in
 * the worker bootstrap (they are, via EventListeners::register() in the console).
 */
final class DispatchEventJob implements Job
{
    public function handle(array $payload): void
    {
        $event = (string) ($payload['event'] ?? '');
        if ($event !== '') {
            Events::dispatch($event, $payload['payload'] ?? null);
        }
    }
}
