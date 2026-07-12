<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Core\CacheManager;

/** Flush every entry from the configured cache store. */
final class CacheClearCommand implements Command
{
    public function name(): string
    {
        return 'cache:clear';
    }

    public function description(): string
    {
        return 'Flush every entry from the configured cache store.';
    }

    public function run(array $args): int
    {
        CacheManager::flush();
        fwrite(STDOUT, "Cache cleared.\n");
        return 0;
    }
}
