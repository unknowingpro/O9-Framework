<?php
declare(strict_types=1);

namespace Tests\Core\Fixtures {
    use App\Core\Job;

    final class RecordingJob implements Job
    {
        /** @var list<array<string,mixed>> */
        public static array $handled = [];

        public function handle(array $payload): void
        {
            self::$handled[] = $payload;
        }
    }

    final class FailingJob implements Job
    {
        public function handle(array $payload): void
        {
            throw new \RuntimeException('job blew up');
        }
    }

    final class NotAJob
    {
    }
}

namespace Tests\Core {

use App\Core\Database;
use App\Core\Events;
use App\Core\Queue;
use PHPUnit\Framework\TestCase;
use Tests\Core\Fixtures\FailingJob;
use Tests\Core\Fixtures\NotAJob;
use Tests\Core\Fixtures\RecordingJob;

final class QueueTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue TEXT NOT NULL,
                job TEXT NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at INTEGER NOT NULL,
                reserved_at INTEGER,
                created_at INTEGER NOT NULL
            )'
        );
        $this->db->pdo()->exec('DELETE FROM jobs');
        RecordingJob::$handled = [];
        Events::flush();
    }

    protected function tearDown(): void
    {
        Events::flush();
    }

    public function testPushEnqueuesAndSizeCounts(): void
    {
        $id = Queue::push(RecordingJob::class, ['n' => 1]);
        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, Queue::size());
        $this->assertSame(0, Queue::size('other'));
    }

    public function testDelayedJobIsNotReservedUntilAvailable(): void
    {
        Queue::push(RecordingJob::class, [], 3600);
        $this->assertNull(Queue::reserve());
        $this->assertSame(0, Queue::work());
        $this->assertSame(1, Queue::size()); // still queued for later
    }

    public function testReserveClaimsAtomicallyAndIncrementsAttempts(): void
    {
        $id = Queue::push(RecordingJob::class);
        $job = Queue::reserve();
        $this->assertNotNull($job);
        $this->assertSame($id, (int) $job['id']);
        $this->assertSame(1, (int) $job['attempts']);
        // The row is now reserved — a concurrent worker gets nothing.
        $this->assertNull(Queue::reserve());
    }

    public function testStaleReservationFromCrashedWorkerIsReclaimed(): void
    {
        $id = Queue::push(RecordingJob::class);
        $this->assertNotNull(Queue::reserve());

        // Simulate a hard-crashed worker: the reservation is older than the
        // retry_after window but the row was never released or buried.
        $stale = time() - ((int) config('worker.queue_retry_after', 3600) + 10);
        $this->db->raw('UPDATE jobs SET reserved_at = ? WHERE id = ?', [$stale, $id]);

        $job = Queue::reserve();
        $this->assertNotNull($job, 'a stale reservation must become claimable again');
        $this->assertSame($id, (int) $job['id']);
        $this->assertSame(2, (int) $job['attempts'], 'reclaim counts as a new attempt');
    }

    public function testBuriedJobIsNeverReclaimedEvenWhenStale(): void
    {
        $id = Queue::push(FailingJob::class);
        // Bury it: attempts at MAX with a reservation left in place.
        $stale = time() - ((int) config('worker.queue_retry_after', 3600) + 10);
        $this->db->raw(
            'UPDATE jobs SET attempts = ?, reserved_at = ? WHERE id = ?',
            [Queue::MAX_ATTEMPTS, $stale, $id]
        );

        $this->assertNull(Queue::reserve(), 'buried jobs stay buried');
    }

    public function testWorkRunsHandlerAndDeletesOnSuccess(): void
    {
        Queue::push(RecordingJob::class, ['user_id' => 7, 'note' => 'سلام']);
        Queue::push(RecordingJob::class, ['user_id' => 8]);
        $this->assertSame(2, Queue::work());
        $this->assertSame([['user_id' => 7, 'note' => 'سلام'], ['user_id' => 8]], RecordingJob::$handled);
        $this->assertSame(0, Queue::size());
    }

    public function testWorkRespectsMaxAndQueueName(): void
    {
        Queue::push(RecordingJob::class, ['a' => 1], 0, 'mail');
        Queue::push(RecordingJob::class, ['a' => 2], 0, 'mail');
        $this->assertSame(0, Queue::work(10)); // default queue is empty
        $this->assertSame(1, Queue::work(1, 'mail'));
        $this->assertSame(1, Queue::size('mail'));
    }

    public function testFailedJobIsReleasedWithBackoffThenBuried(): void
    {
        $id = Queue::push(FailingJob::class);

        // Attempt 1: released with backoff in the future.
        $this->assertSame(1, Queue::work());
        $row = $this->row($id);
        $this->assertNull($row['reserved_at']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertGreaterThan(time(), (int) $row['available_at']);

        // Attempts 2 and 3: force availability, fail again.
        $this->makeAvailable($id);
        $this->assertSame(1, Queue::work());
        $this->makeAvailable($id);
        $this->assertSame(1, Queue::work());

        // Attempt 3 hit MAX_ATTEMPTS: buried — left reserved for inspection.
        $row = $this->row($id);
        $this->assertSame(Queue::MAX_ATTEMPTS, (int) $row['attempts']);
        $this->assertNotNull($row['reserved_at']);

        // A buried job is never re-reserved.
        $this->makeAvailable($id, false);
        $this->assertSame(0, Queue::work());
    }

    public function testNonJobClassIsTreatedAsFailure(): void
    {
        $id = Queue::push(NotAJob::class);
        $this->assertSame(1, Queue::work());
        $row = $this->row($id);
        $this->assertNull($row['reserved_at']); // released for retry, not deleted
        $this->assertSame(1, (int) $row['attempts']);
    }

    public function testDispatchAsyncRoundTripsThroughTheWorker(): void
    {
        $seen = [];
        Events::listen('report.ready', static function ($p) use (&$seen): void {
            $seen[] = $p;
        });
        $jobId = Events::dispatchAsync('report.ready', ['report_id' => 12]);
        $this->assertGreaterThan(0, $jobId);
        $this->assertSame([], $seen); // nothing fired synchronously
        $this->assertSame(1, Queue::work());
        $this->assertSame([['report_id' => 12]], $seen);
        $this->assertSame(0, Queue::size());
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $row = $this->db->raw('SELECT * FROM jobs WHERE id = ?', [$id])->fetch();
        $this->assertIsArray($row, "job row $id must exist");
        return $row;
    }

    private function makeAvailable(int $id, bool $clearReserved = true): void
    {
        $sql = $clearReserved
            ? 'UPDATE jobs SET available_at = ?, reserved_at = NULL WHERE id = ?'
            : 'UPDATE jobs SET available_at = ? WHERE id = ?';
        $this->db->raw($sql, [time() - 1, $id]);
    }
}

}
