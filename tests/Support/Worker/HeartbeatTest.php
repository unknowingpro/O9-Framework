<?php
declare(strict_types=1);

namespace Tests\Support\Worker;

use App\Support\Worker\Heartbeat;
use PHPUnit\Framework\TestCase;

final class HeartbeatTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/o9-hb-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testRunDirResolution(): void
    {
        // Absolute override wins verbatim (trailing slash trimmed).
        $this->assertSame('/var/run/o9', Heartbeat::runDir('/var/run/o9/'));
        // Relative values resolve under the project root.
        $this->assertSame(base_path('storage/run'), Heartbeat::runDir());
        $this->assertSame(base_path('custom/run'), Heartbeat::runDir('custom/run'));
    }

    public function testWriteReadRoundTrip(): void
    {
        Heartbeat::write('sync', ['iterations' => 3], $this->dir);
        $hb = Heartbeat::read('sync', $this->dir);
        $this->assertNotNull($hb);
        $this->assertSame('sync', $hb['worker']);
        $this->assertSame(3, $hb['iterations']);
        $this->assertSame(getmypid(), $hb['pid']);
        $this->assertEqualsWithDelta(time(), $hb['ts'], 2);
        // The atomic temp file must not linger.
        $this->assertCount(1, glob($this->dir . '/*') ?: []);
    }

    public function testReadMissingOrCorruptHeartbeatReturnsNull(): void
    {
        $this->assertNull(Heartbeat::read('ghost', $this->dir));
        file_put_contents($this->dir . '/broken.heartbeat', '{not json');
        $this->assertNull(Heartbeat::read('broken', $this->dir));
    }

    public function testAllCollectsEveryWorkerKeyedByName(): void
    {
        Heartbeat::write('alpha', [], $this->dir);
        Heartbeat::write('beta', [], $this->dir);
        $all = Heartbeat::all($this->dir);
        $this->assertSame(['alpha', 'beta'], array_keys($all));
        $this->assertSame('beta', $all['beta']['worker']);
    }

    public function testAgeSeconds(): void
    {
        $this->assertSame(40, Heartbeat::ageSeconds(['ts' => 100], 140));
        $this->assertSame(0, Heartbeat::ageSeconds(['ts' => 200], 140)); // clock skew clamps to 0
        $this->assertSame(140, Heartbeat::ageSeconds([], 140));          // missing ts = epoch
    }
}
