<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Support\Delta;

/**
 * Outbound delta sync sample: a client sends ?updated_since=<cursor> and
 * gets back only the rows changed since, plus tombstones for rows deleted
 * since — see Support\Delta. This demo syncs the `users` table (id +
 * updated_at only) against the current user; point it at any table your
 * app wants a client to sync incrementally.
 */
final class SyncController extends BaseController
{
    public function users(Request $request): never
    {
        $userId = $this->userId();
        if ($userId === null) {
            Response::unauthorized();
        }

        $since = Delta::since($request);
        if ($since === null) {
            Response::ok(['changed' => [], 'deleted' => [], 'synced_at' => gmdate('Y-m-d H:i:s')]);
        }

        $result = Delta::rows(Database::getInstance(), 'users', 'id', $userId, $since, 'updated_at', false);
        Response::ok($result + ['synced_at' => gmdate('Y-m-d H:i:s')]);
    }
}
