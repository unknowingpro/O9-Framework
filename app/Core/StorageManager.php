<?php
declare(strict_types=1);

namespace App\Core;

use App\Storage\FtpDriver;
use App\Storage\LocalDriver;
use App\Storage\S3Driver;
use App\Storage\SftpDriver;
use App\Storage\StorageDriverInterface;
use App\Storage\WebDavDriver;

/**
 * StorageManager — routes reads/writes across the configured storage
 * drivers (config/storage.php), by mode:
 *
 *   primary  write to the primary driver only (default)
 *   all      fan out: write to every configured backend
 *   sync     write to the primary now; queue a replication job for the rest
 *            (storage/jobs/*.json — a worker drains it; wiring the worker
 *            itself is app-side, this only writes the job file)
 *
 * Reads (get/exists) try the primary first, then each driver named in
 * config('storage.fallback') in order — the PRIMARY/FALLBACK convention
 * used across the framework's other driver-backed subsystems.
 */
final class StorageManager
{
    private static ?self $instance = null;

    /** @var array<string, StorageDriverInterface> */
    private array $drivers = [];
    private string $mode;
    private string $primaryName;
    /** @var list<string> */
    private array $fallbackChain;

    private function __construct()
    {
        $this->mode        = (string) config('storage.mode', 'primary');
        $this->primaryName = (string) config('storage.primary', 'local');

        /** @var list<string> $backends */
        $backends = (array) config('storage.backends', ['local']);
        foreach ($backends as $name) {
            try {
                $this->drivers[$name] = $this->make($name);
            } catch (\Throwable $e) {
                if (class_exists(Logger::class)) {
                    Logger::error('storage.driver.boot_failed', ['driver' => $name, 'error' => $e->getMessage()]);
                }
            }
        }
        if ($this->drivers === []) {
            // Local always works — guarantee at least one usable driver.
            $this->drivers['local'] = new LocalDriver(['root' => config('storage.upload_dir')]);
        }
        if (!isset($this->drivers[$this->primaryName])) {
            $this->primaryName = (string) (array_key_first($this->drivers) ?? 'local');
        }

        /** @var list<string> $fallback */
        $fallback = (array) config('storage.fallback', []);
        $this->fallbackChain = $fallback;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** @internal test reset */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /** Inject/override a driver on the current instance (tests, or wiring an app-side driver). */
    public function setDriver(string $name, StorageDriverInterface $driver): void
    {
        $this->drivers[$name] = $driver;
    }

    /**
     * Override the read-fallback order (tests, or dynamic reconfiguration).
     *
     * @param list<string> $names
     */
    public function setFallbackChain(array $names): void
    {
        $this->fallbackChain = array_values($names);
    }

    public function mode(): string { return $this->mode; }

    public function primaryName(): string { return $this->primaryName; }

    public function primary(): StorageDriverInterface
    {
        return $this->drivers[$this->primaryName]
            ?? throw new \RuntimeException('StorageManager: no drivers configured');
    }

    public function driver(string $name): StorageDriverInterface
    {
        return $this->drivers[$name] ?? throw new \RuntimeException("StorageManager: driver '$name' not loaded");
    }

    /** @return array<string, StorageDriverInterface> */
    public function all(): array { return $this->drivers; }

    public function put(string $tmpPath, string $remotePath, string $uuid = ''): bool
    {
        return match ($this->mode) {
            'all'  => $this->putAll($tmpPath, $remotePath, $uuid),
            'sync' => $this->putSync($tmpPath, $remotePath, $uuid),
            default => $this->primary()->put($tmpPath, $remotePath, $uuid),
        };
    }

    private function putAll(string $tmpPath, string $remotePath, string $uuid): bool
    {
        $ok = true;
        foreach ($this->drivers as $driver) {
            $ok = $driver->put($tmpPath, $remotePath, $uuid) && $ok;
        }
        return $ok;
    }

    private function putSync(string $tmpPath, string $remotePath, string $uuid): bool
    {
        $ok = $this->primary()->put($tmpPath, $remotePath, $uuid);
        $this->queueSync($remotePath, $tmpPath);
        return $ok;
    }

    /** Read with fallback: primary first, then config('storage.fallback') drivers in order. */
    public function get(string $remotePath): string
    {
        $lastError = null;
        foreach ($this->readChain() as $name) {
            try {
                return $this->drivers[$name]->get($remotePath);
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }
        throw $lastError ?? new \RuntimeException("StorageManager: no driver could read $remotePath");
    }

    public function exists(string $remotePath): bool
    {
        foreach ($this->readChain() as $name) {
            try {
                if ($this->drivers[$name]->exists($remotePath)) {
                    return true;
                }
            } catch (\Throwable) {
                // Try the next driver in the chain.
            }
        }
        return false;
    }

    /** Delete from every configured driver; returns true only if all succeeded. */
    public function delete(string $remotePath): bool
    {
        $ok = true;
        foreach ($this->drivers as $name => $driver) {
            try {
                $ok = $driver->delete($remotePath) && $ok;
            } catch (\Throwable $e) {
                $ok = false;
                if (class_exists(Logger::class)) {
                    Logger::error('storage.delete_failed', ['driver' => $name, 'path' => $remotePath, 'error' => $e->getMessage()]);
                }
            }
        }
        return $ok;
    }

    /**
     * Primary, then the configured fallback drivers, de-duplicated.
     *
     * @return list<string>
     */
    private function readChain(): array
    {
        $chain = array_unique(array_merge([$this->primaryName], $this->fallbackChain));
        return array_values(array_filter($chain, fn (string $n): bool => isset($this->drivers[$n])));
    }

    /** Write a replication job for the secondary drivers in 'sync' mode. A worker drains storage/jobs/. */
    private function queueSync(string $remotePath, string $tmpPath): void
    {
        $secondaries = array_values(array_filter(
            array_keys($this->drivers),
            fn (string $name): bool => $name !== $this->primaryName
        ));
        if ($secondaries === []) {
            return;
        }
        $dir  = (string) config('storage.sync_dir', base_path('storage/jobs'));
        $file = $dir . '/sync_' . bin2hex(random_bytes(8)) . '.json';
        Storage::writeJson($file, [
            'op'          => 'put',
            'remote_path' => $remotePath,
            'local_tmp'   => $tmpPath,
            'backends'    => $secondaries,
            'queued_at'   => time(),
        ]);
    }

    private function make(string $name): StorageDriverInterface
    {
        return match (true) {
            $name === 'local'  => new LocalDriver(['root' => config('storage.upload_dir')]),
            $name === 's3'     => new S3Driver((array) config('storage.s3', [])),
            $name === 'sftp'   => new SftpDriver((array) config('storage.sftp', [])),
            $name === 'ftp'    => new FtpDriver((array) config('storage.ftp', [])),
            $name === 'webdav' => new WebDavDriver((array) config('storage.webdav', []), 'webdav'),
            // Numbered WebDAV instances: webdav1, webdav2, … each reads config('storage.webdav1'), etc.
            (bool) preg_match('/^webdav(\d+)$/', $name) === true
                => new WebDavDriver((array) config('storage.' . $name, []), $name),
            default => throw new \RuntimeException("StorageManager: unknown driver '$name'"),
        };
    }
}
