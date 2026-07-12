<?php
declare(strict_types=1);

namespace Tests\Console\Commands;

use App\Console\Commands\MakeJobCommand;
use PHPUnit\Framework\TestCase;

final class MakeJobCommandTest extends TestCase
{
    public function testCreatesAJobImplementingTheContract(): void
    {
        $path = base_path('app/Jobs/__TestSomethingJob.php');
        @unlink($path);
        try {
            $exit = (new MakeJobCommand())->run(['__TestSomething']);
            $this->assertSame(0, $exit);
            $contents = (string) file_get_contents($path);
            $this->assertStringContainsString('final class __TestSomethingJob implements Job', $contents);
        } finally {
            @unlink($path);
        }
    }

    public function testJobSuffixIsNotDuplicated(): void
    {
        $path = base_path('app/Jobs/__TestSomethingJob.php');
        @unlink($path);
        try {
            (new MakeJobCommand())->run(['__TestSomethingJob']);
            $contents = (string) file_get_contents($path);
            $this->assertStringNotContainsString('JobJob', $contents);
        } finally {
            @unlink($path);
        }
    }

    public function testRefusesToOverwriteAnExistingFile(): void
    {
        $path = base_path('app/Jobs/__TestExistingJob.php');
        file_put_contents($path, '<?php // existing');
        try {
            $exit = (new MakeJobCommand())->run(['__TestExisting']);
            $this->assertSame(1, $exit);
        } finally {
            @unlink($path);
        }
    }

    public function testRequiresAnArgument(): void
    {
        $this->assertSame(1, (new MakeJobCommand())->run([]));
    }
}
