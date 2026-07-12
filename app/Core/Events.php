<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Events — minimal synchronous event dispatcher.
 *
 * Decouples cross-cutting side effects (notifications, audit, …) from the
 * services that trigger them: a service dispatches a named event with a payload;
 * listeners registered in EventListeners react. Listener exceptions are isolated
 * and logged — a failing side effect never breaks the triggering action.
 *
 * Listeners run synchronously. For slow work (push, Telegram, email) use
 * dispatchAsync(), which enqueues a job so the request returns immediately and
 * the queue worker re-dispatches the event (with the same listeners).
 */
final class Events
{
    /** @var array<string, list<callable>> */
    private static array $listeners = [];

    public static function listen(string $event, callable $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    /** Fire all listeners for $event synchronously. */
    public static function dispatch(string $event, mixed $payload = null): void
    {
        foreach (self::$listeners[$event] ?? [] as $listener) {
            try {
                $listener($payload);
            } catch (\Throwable $e) {
                if (class_exists(Logger::class)) {
                    Logger::error('event.listener.failed', ['event' => $event, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    /**
     * Queue an event for ASYNCHRONOUS dispatch: enqueues a DispatchEventJob; the
     * queue worker re-dispatches it synchronously (listeners are registered in
     * the worker bootstrap). Payload must be JSON-serialisable. Returns the job id.
     */
    public static function dispatchAsync(string $event, mixed $payload = null, int $delaySeconds = 0): int
    {
        return Queue::push(\App\Jobs\DispatchEventJob::class, ['event' => $event, 'payload' => $payload], $delaySeconds);
    }

    public static function forget(string $event): void
    {
        unset(self::$listeners[$event]);
    }

    /** @internal for tests / bootstrapping */
    public static function flush(): void
    {
        self::$listeners = [];
    }
}
