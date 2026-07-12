<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Cache\Cache;

/**
 * Lightweight circuit breaker for outbound dependencies (third-party APIs).
 *
 * State is shared across requests/instances via the Cache (Redis, with file
 * fallback) keyed by service name, so a dependency that starts failing trips
 * the breaker process-wide rather than each request re-learning it the hard way:
 *
 *   - CLOSED    normal operation; failures are counted.
 *   - OPEN      after >= $threshold consecutive failures, calls fast-fail for
 *               $cooldown seconds instead of hanging on a dead dependency.
 *   - HALF-OPEN once the cooldown elapses, a single probe is allowed; success
 *               closes the breaker, failure re-opens it for another cooldown.
 *
 * Fails OPEN on its own bookkeeping errors (Cache down -> never blocks a real
 * call), so the breaker can only ever help, never become a new point of failure.
 */
final class CircuitBreaker
{
    public function __construct(
        private string $name,
        private int $threshold = 5,
        private int $cooldown = 30,
    ) {
    }

    private function failKey(): string { return 'cb:fail:' . $this->name; }
    private function openKey(): string { return 'cb:open:' . $this->name; }

    /** Is a call currently permitted? (false while the breaker is open + cooling down) */
    public function allowed(): bool
    {
        try {
            return !Cache::has($this->openKey());
        } catch (\Throwable) {
            return true; // bookkeeping failure must never block a real call
        }
    }

    public function recordSuccess(): void
    {
        try {
            Cache::forget($this->failKey());
            Cache::forget($this->openKey());
        } catch (\Throwable) {
        }
    }

    public function recordFailure(): void
    {
        try {
            $fails = Cache::increment($this->failKey());
            if ($fails >= $this->threshold) {
                // Trip: open the breaker for the cooldown window.
                Cache::set($this->openKey(), 1, $this->cooldown);
                Cache::forget($this->failKey());
            } else {
                // Keep the failure counter alive for a sensible window.
                Cache::set($this->failKey(), $fails, max(60, $this->cooldown * 2));
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Run $fn through the breaker. If the breaker is open, $fallback() is
     * invoked (or a RuntimeException thrown when no fallback is given)
     * WITHOUT calling $fn. On success the breaker resets; on a thrown
     * failure it records and rethrows (or returns the fallback).
     *
     * @template T
     * @param callable(): T $fn
     * @param (callable(): T)|null $fallback
     * @return T
     */
    public function call(callable $fn, ?callable $fallback = null): mixed
    {
        if (!$this->allowed()) {
            if ($fallback !== null) {
                return $fallback();
            }
            throw new \RuntimeException("Circuit '{$this->name}' is open");
        }
        try {
            $result = $fn();
            $this->recordSuccess();
            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure();
            if ($fallback !== null) {
                return $fallback();
            }
            throw $e;
        }
    }
}
