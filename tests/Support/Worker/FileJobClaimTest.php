<?php
declare(strict_types=1);

namespace Tests\Support\Worker;

use App\Support\Worker\FileJobClaim;
use PHPUnit\Framework\TestCase;

final class FileJobClaimTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/o9-claim-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testClaimRenamesTheJobFileExactlyOnce(): void
    {
        $job = $this->dir . '/job-1.json';
        file_put_contents($job, '{"id":1}');

        $claimed = FileJobClaim::claim($job);
        $this->assertSame($job . '.working', $claimed);
        $this->assertFileDoesNotExist($job);
        $this->assertFileExists($claimed);
        $this->assertSame('{"id":1}', file_get_contents($claimed));

        // The loser of the race sees the original gone and gets null.
        $this->assertNull(FileJobClaim::claim($job));
    }

    public function testClaimOfMissingFileReturnsNull(): void
    {
        $this->assertNull(FileJobClaim::claim($this->dir . '/never-existed.json'));
    }
}
