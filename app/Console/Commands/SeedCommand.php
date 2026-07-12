<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Core\Seeder;

/**
 * Run database seeders.
 *
 *   php setup/bin/console db:seed                  # run all seeders
 *   php setup/bin/console db:seed --class=UserSeed  # run one seeder
 *   php setup/bin/console db:seed --fresh           # truncate before seeding
 */
final class SeedCommand implements Command
{
    private readonly string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? dirname(__DIR__, 3) . '/setup/database/seeders';
    }

    public function name(): string
    {
        return 'db:seed';
    }

    public function description(): string
    {
        return 'Run database seeders (--class=Name for one, --fresh to truncate first).';
    }

    public function run(array $args): int
    {
        if (!is_dir($this->dir)) {
            fwrite(STDOUT, "No seeders directory found at {$this->dir}\n");
            return 0;
        }

        $files = glob($this->dir . '/*.php') ?: [];
        sort($files);

        if ($files === []) {
            fwrite(STDOUT, "No seeder files found in {$this->dir}\n");
            return 0;
        }

        $className = null;
        foreach ($args as $a) {
            if (str_starts_with($a, '--class=')) {
                $className = substr($a, 8);
            }
        }

        $ran = 0;

        foreach ($files as $path) {
            require_once $path;

            $base   = basename($path, '.php');
            $fqcn   = $this->resolveClass($base);

            if ($fqcn === null) {
                fwrite(STDERR, "Skipping {$base}: no matching class found\n");
                continue;
            }

            if ($className !== null && $base !== $className) {
                continue;
            }

            if (!is_subclass_of($fqcn, Seeder::class)) {
                fwrite(STDERR, "Skipping {$base}: class does not extend " . Seeder::class . "\n");
                continue;
            }

            /** @var Seeder $seeder */
            $seeder = new $fqcn();
            $seeder->run($args);
            $ran++;
            fwrite(STDOUT, "Seeded: {$base}\n");
        }

        fwrite(STDOUT, "Done (" . $ran . " seeder(s) ran).\n");
        return 0;
    }

    /**
     * Try to resolve a short class name to its fully-qualified form.
     * Seeders can live in any namespace — the convention is App\Database\Seeders\{Name}
     * or simply the global namespace if the seeder file does not declare one.
     */
    private function resolveClass(string $base): ?string
    {
        $candidates = [
            "App\\Database\\Seeders\\{$base}",
            $base,
        ];

        foreach ($candidates as $c) {
            if (class_exists($c)) {
                return $c;
            }
        }

        return null;
    }
}
