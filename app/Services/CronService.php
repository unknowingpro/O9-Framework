<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Sample scheduled-job logic: routine housekeeping an app typically wants
 * to run daily (prune expired data that has no other natural cleanup
 * point). Wire it into Console\Schedule::define() in app/bootstrap.php:
 *
 *   $s->command('cron:maintenance')->dailyAt(3, 0);
 *
 * and register a Console\Commands\MaintenanceCommand (make:job / a plain
 * Command implementation) that calls CronService::runMaintenance().
 */
final class CronService
{
    /** @return array<string, int> counts of rows pruned per table. */
    public static function runMaintenance(): array
    {
        $db = Database::getInstance();
        $now = gmdate('Y-m-d H:i:s');
        $pruned = [];

        foreach (['jwt_revocations', 'entitlement_overrides'] as $table) {
            if (!$db->tableExists($table)) {
                continue;
            }
            $col = $table === 'jwt_revocations' ? 'exp' : 'expires_at';
            $pruned[$table] = $db->raw(
                "DELETE FROM {$table} WHERE {$col} IS NOT NULL AND {$col} < ?",
                [$now]
            )->rowCount();
        }

        return $pruned;
    }
}
