<?php
declare(strict_types=1);

namespace App\Core;

use App\Support\Worker\Heartbeat;

/**
 * Prometheus text-exposition metrics. render() is a pure formatter; collect()
 * gathers the framework's own operational samples (worker heartbeat
 * liveness). Apps add their own samples by building a list in the same
 * shape and merging it with collect()'s before calling render() — see
 * Controllers/Admin/MonitorController for the wiring pattern.
 */
final class Metrics
{
    /**
     * @param list<array{name: string, type: string, help: string, labels: array<string, string>, value: int|float}> $samples
     */
    public static function render(array $samples): string
    {
        $seen = [];
        $out = '';
        foreach ($samples as $s) {
            if (!isset($seen[$s['name']])) {
                $out .= "# HELP {$s['name']} {$s['help']}\n# TYPE {$s['name']} {$s['type']}\n";
                $seen[$s['name']] = true;
            }
            $lbl = '';
            if ($s['labels'] !== []) {
                $parts = [];
                foreach ($s['labels'] as $k => $v) {
                    $parts[] = $k . '="' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $v) . '"';
                }
                $lbl = '{' . implode(',', $parts) . '}';
            }
            $out .= $s['name'] . $lbl . ' ' . $s['value'] . "\n";
        }
        return $out;
    }

    /**
     * Worker liveness from the heartbeat files under storage/run/ — the only
     * metrics the framework itself knows how to produce. Apps merge their
     * own domain samples (row counts, queue depth, etc.) alongside these.
     *
     * @return list<array{name: string, type: string, help: string, labels: array<string, string>, value: int|float}>
     */
    public static function collect(): array
    {
        $g = static fn (string $n, string $h, int|float $v, array $l = []): array =>
            ['name' => $n, 'type' => 'gauge', 'help' => $h, 'labels' => $l, 'value' => $v];

        $samples = [];
        $maxAge = (int) config('worker.heartbeat_max_age', 120);
        $now = time();
        foreach (Heartbeat::all() as $name => $hb) {
            $age = Heartbeat::ageSeconds($hb, $now);
            $samples[] = $g('o9_worker_up', 'Worker liveness (1=heartbeat fresh)', $age <= $maxAge ? 1 : 0, ['worker' => (string) $name]);
            $samples[] = $g('o9_worker_heartbeat_age_seconds', 'Seconds since worker heartbeat', $age, ['worker' => (string) $name]);
        }
        return $samples;
    }
}
