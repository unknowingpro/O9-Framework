<?php
declare(strict_types=1);

namespace App\Support\Worker;

/**
 * Atomic file-job claim: rename(job → job.working) is atomic on a local FS, so exactly
 * one processor wins a given job file. Lets a daemon and on-demand exec() spawns
 * coexist without double-processing (the file equivalent of a DB conditional-UPDATE claim).
 */
final class FileJobClaim
{
    /** @return string|null the claimed (.working) path, or null if missing / lost the race */
    public static function claim(string $path): ?string
    {
        if (!is_file($path)) { return null; }
        $claimed = $path . '.working';
        return @rename($path, $claimed) ? $claimed : null;
    }
}
