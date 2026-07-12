<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Core\Database;
use App\Services\MigrationsService;

/**
 * Apply all pending database migrations to the active connection.
 *   php setup/bin/console migrate            apply pending
 *   php setup/bin/console migrate --status   list applied/pending without applying
 */
final class MigrateCommand implements Command
{
    public function __construct(private readonly ?MigrationsService $service = null)
    {
    }

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Apply pending database migrations (use --status to preview).';
    }

    public function run(array $args): int
    {
        $svc    = $this->service ?? new MigrationsService();
        $driver = Database::getInstance()->driver();

        if (in_array('--status', $args, true)) {
            $applied = array_map(static fn (array $r): string => (string) $r['name'], $svc->applied());
            $pending = $svc->pending();
            fwrite(STDOUT, "Driver: {$driver}\n");
            fwrite(STDOUT, 'Applied (' . count($applied) . '): ' . (implode(', ', $applied) ?: '-') . "\n");
            fwrite(STDOUT, 'Pending (' . count($pending) . '): ' . (implode(', ', $pending) ?: '-') . "\n");
            return 0;
        }

        $pending = $svc->pending();
        if ($pending === []) {
            fwrite(STDOUT, "Nothing to apply [driver={$driver}].\n");
            return 0;
        }
        fwrite(STDOUT, 'Applying ' . count($pending) . ' migration(s): ' . implode(', ', $pending) . "\n");
        try {
            $applied = $svc->applyAll();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
            return 1;
        }
        fwrite(STDOUT, 'Applied ' . count($applied) . " migration(s) [driver={$driver}].\n");
        return 0;
    }
}
