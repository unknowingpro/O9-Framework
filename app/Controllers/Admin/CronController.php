<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Console\Commands\ScheduleRunCommand;
use App\Core\BaseController;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * HTTP-triggered cron endpoint, for hosts without shell/crontab access.
 * Protected by a shared secret (config('app.cron_secret')), NOT by
 * ['Auth:admin'] — an external uptime/cron pinger calls this without a
 * session. Delegates to the same ScheduleRunCommand the CLI `schedule:run`
 * uses, so both entry points run identical, single-tested logic. Prefer
 * real cron + setup/bin/console schedule:run when shell access is available.
 */
final class CronController extends BaseController
{
    public function run(Request $request): never
    {
        $secret = (string) config('app.cron_secret', '');
        $given  = (string) $request->query('secret', $request->header('X-Cron-Secret'));
        if ($secret === '' || !hash_equals($secret, $given)) {
            throw HttpException::unauthorized('Invalid cron secret.');
        }

        $exit = (new ScheduleRunCommand())->run([]);
        Response::ok(['exit' => $exit]);
    }
}
