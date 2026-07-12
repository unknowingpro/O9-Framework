<?php
declare(strict_types=1);

namespace Tests\Console\Commands\Fixtures {
    use App\Core\Job;

    final class NoopJob implements Job
    {
        public static int $handled = 0;

        public function handle(array $payload): void
        {
            self::$handled++;
        }
    }
}

namespace Tests\Console\Commands {

use App\Console\Commands\QueueWorkCommand;
use App\Core\Database;
use App\Core\Queue;
use PHPUnit\Framework\TestCase;
use Tests\Console\Commands\Fixtures\NoopJob;

final class QueueWorkCommandTest extends TestCase
{
    protected function setUp(): void
    {
        $db = Database::getInstance();
        $db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, queue TEXT NOT NULL, job TEXT NOT NULL,
                payload TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
                available_at INTEGER NOT NULL, reserved_at INTEGER, created_at INTEGER NOT NULL
            )'
        );
        $db->pdo()->exec('DELETE FROM jobs');
        NoopJob::$handled = 0;
    }

    public function testProcessesJobsOnTheDefaultQueue(): void
    {
        Queue::push(NoopJob::class);
        Queue::push(NoopJob::class);
        $exit = (new QueueWorkCommand())->run([]);
        $this->assertSame(0, $exit);
        $this->assertSame(2, NoopJob::$handled);
        $this->assertSame(0, Queue::size());
    }

    public function testRespectsExplicitQueueNameAndMax(): void
    {
        Queue::push(NoopJob::class, [], 0, 'mail');
        Queue::push(NoopJob::class, [], 0, 'mail');
        $exit = (new QueueWorkCommand())->run(['mail', '1']);
        $this->assertSame(0, $exit);
        $this->assertSame(1, NoopJob::$handled);
        $this->assertSame(1, Queue::size('mail'));
    }

    public function testExitsCleanlyWithNothingQueued(): void
    {
        $this->assertSame(0, (new QueueWorkCommand())->run([]));
    }
}

}
