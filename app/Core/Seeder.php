<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Base class for database seeders. Concrete seeders live in
 * setup/database/seeders/ and are run via:
 *
 *   php setup/bin/console db:seed
 *
 * Each sealer overrides run() to insert seed data using the Database
 * connection or BaseModel helpers. The class is auto-discovered by naming
 * convention: the filename (without .php) is treated as the seeder name.
 *
 * Usage:
 *   php setup/bin/console db:seed                  # run all seeders
 *   php setup/bin/console db:seed --class=UserSeed  # run one seeder
 *   php setup/bin/console db:seed --fresh           # truncate then seed
 */
abstract class Seeder
{
    /**
     * @param array<string, mixed> $args CLI args passed through from the command.
     *        --fresh  truncate tables before seeding (caller decides what to truncate)
     *        --class  run only this seeder class
     */
    abstract public function run(array $args = []): void;

    /**
     * Truncate a table (respects FK constraints — deletes all rows, no DDL).
     * Override to use TRUNCATE on MySQL when FK checks are off.
     */
    protected function truncate(string $table): void
    {
        Database::getInstance()->raw("DELETE FROM {$table}");
    }

    /**
     * Check if --fresh was passed.
     */
    protected function isFresh(array $args): bool
    {
        return in_array('--fresh', $args, true);
    }

    /**
     * Read and return a JSON fixture file from the seeders directory.
     *
     * @return list<array<string, mixed>>
     */
    protected function loadJson(string $filename): array
    {
        $path = dirname(__DIR__, 2) . '/setup/database/seeders/' . ltrim($filename, '/');
        if (!is_file($path)) {
            throw new \RuntimeException("Seeder fixture not found: {$path}");
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON fixture: {$path}");
        }
        return $data;
    }
}
