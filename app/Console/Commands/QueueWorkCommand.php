<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Core\Queue;

/** Process queued background jobs once (args: [queue] [max]). */
final class QueueWorkCommand implements Command
{
    public function name(): string
    {
        return 'queue:work';
    }

    public function description(): string
    {
        return 'Process queued background jobs (args: [queue] [max]).';
    }

    public function run(array $args): int
    {
        $queue = $args[0] ?? 'default';
        $max   = isset($args[1]) ? max(1, (int) $args[1]) : PHP_INT_MAX;
        $n     = Queue::work($max, $queue);
        fwrite(STDOUT, gmdate('Y-m-d H:i:s') . " UTC - processed {$n} job" . ($n === 1 ? '' : 's') . " on '{$queue}'.\n");
        return 0;
    }
}
