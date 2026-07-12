<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\UrlJobStore;
use PHPUnit\Framework\TestCase;

final class UrlJobStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = base_path('storage/jobs/url');
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    private function jobId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public function testCreateThenReadReturnsPendingState(): void
    {
        $id = $this->jobId();
        UrlJobStore::create($id);
        $job = UrlJobStore::read($id);
        $this->assertSame('pending', $job['status']);
        $this->assertSame(0, $job['downloaded']);
    }

    public function testWriteUpdatesTheJobState(): void
    {
        $id = $this->jobId();
        UrlJobStore::create($id);
        UrlJobStore::write($id, ['status' => 'downloading', 'downloaded' => 500, 'total' => 1000, 'pct' => 50]);
        $job = UrlJobStore::read($id);
        $this->assertSame('downloading', $job['status']);
        $this->assertSame(50, $job['pct']);
        $this->assertArrayHasKey('ts', $job);
    }

    public function testFinishRecordsTheResultingFile(): void
    {
        $id = $this->jobId();
        UrlJobStore::create($id);
        UrlJobStore::finish($id, ['path' => '/uploads/x.mp4', 'size' => 12345]);
        $job = UrlJobStore::read($id);
        $this->assertSame('done', $job['status']);
        $this->assertSame(100, $job['pct']);
        $this->assertSame('/uploads/x.mp4', $job['file']['path']);
    }

    public function testFailRecordsTheError(): void
    {
        $id = $this->jobId();
        UrlJobStore::create($id);
        UrlJobStore::fail($id, 'connection reset');
        $job = UrlJobStore::read($id);
        $this->assertSame('error', $job['status']);
        $this->assertSame('connection reset', $job['error']);
    }

    public function testReadOfUnknownJobReturnsNull(): void
    {
        $this->assertNull(UrlJobStore::read($this->jobId()));
    }

    public function testInvalidJobIdIsRejected(): void
    {
        // The sanitiser keeps only [a-f0-9]; an id with none of those
        // characters (uppercase-only path traversal attempt) becomes empty.
        $this->expectException(\InvalidArgumentException::class);
        UrlJobStore::write('../../ETC/PWD', ['status' => 'x']);
    }

    public function testInvalidJobIdCharactersAreStrippedNotBypassed(): void
    {
        // Only [a-f0-9] survives the sanitiser; an all-invalid id (no digits,
        // no a-f) becomes empty -> rejected.
        $this->expectException(\InvalidArgumentException::class);
        UrlJobStore::write('ZZZZ-NOT-HEX-ZZZZ', ['status' => 'x']);
    }
}
