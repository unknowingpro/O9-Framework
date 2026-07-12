<?php
declare(strict_types=1);

namespace App\Support\Worker;

/**
 * Per-worker liveness heartbeat as an atomically-written JSON file under storage/run/.
 * Read by /metrics and alerting crons to detect a dead/stale worker.
 */
final class Heartbeat
{
    /** Absolute run dir; $runDir overrides config('worker.run_dir'). */
    public static function runDir(?string $runDir = null): string
    {
        if ($runDir !== null && str_starts_with($runDir, '/')) {
            return rtrim($runDir, '/');
        }
        $rel = $runDir ?? (string) config('worker.run_dir', 'storage/run');
        return base_path(trim($rel, '/'));
    }

    /** @param array<string,mixed> $extra */
    public static function write(string $name, array $extra = [], ?string $runDir = null): void
    {
        $dir = self::runDir($runDir);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $path = $dir . '/' . $name . '.heartbeat';
        $data = array_merge(['worker' => $name, 'pid' => getmypid(), 'ts' => time()], $extra);
        $json = json_encode($data);
        if ($json === false) { return; } // never write a zero-byte heartbeat
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $json) !== false) {
            @rename($tmp, $path); // atomic replace
        }
    }

    /** @return array<string,mixed>|null */
    public static function read(string $name, ?string $runDir = null): ?array
    {
        $path = self::runDir($runDir) . '/' . $name . '.heartbeat';
        if (!is_file($path)) { return null; }
        $d = json_decode((string) file_get_contents($path), true);
        return is_array($d) ? $d : null;
    }

    /** @return array<string,array<string,mixed>> keyed by worker name */
    public static function all(?string $runDir = null): array
    {
        $out = [];
        foreach (glob(self::runDir($runDir) . '/*.heartbeat') ?: [] as $f) {
            $d = json_decode((string) file_get_contents($f), true);
            if (is_array($d) && isset($d['worker'])) {
                $out[(string) $d['worker']] = $d;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $hb */
    public static function ageSeconds(array $hb, int $now): int
    {
        return max(0, $now - (int) ($hb['ts'] ?? 0));
    }
}
