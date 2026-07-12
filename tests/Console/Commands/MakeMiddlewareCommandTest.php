<?php
declare(strict_types=1);

namespace Tests\Console\Commands;

use App\Console\Commands\MakeMiddlewareCommand;
use PHPUnit\Framework\TestCase;

final class MakeMiddlewareCommandTest extends TestCase
{
    public function testCreatesAMiddlewareImplementingTheContract(): void
    {
        $path = base_path('app/Middleware/__TestGate.php');
        @unlink($path);
        try {
            $exit = (new MakeMiddlewareCommand())->run(['__TestGate']);
            $this->assertSame(0, $exit);
            $contents = (string) file_get_contents($path);
            $this->assertStringContainsString('final class __TestGate implements Middleware', $contents);
            $this->assertStringContainsString('public function handle(Request $request, ?string $arg = null): void', $contents);
        } finally {
            @unlink($path);
        }
    }

    public function testRefusesToOverwriteAnExistingFile(): void
    {
        $path = base_path('app/Middleware/__TestExisting.php');
        file_put_contents($path, '<?php // existing');
        try {
            $exit = (new MakeMiddlewareCommand())->run(['__TestExisting']);
            $this->assertSame(1, $exit);
        } finally {
            @unlink($path);
        }
    }

    public function testRequiresAnArgument(): void
    {
        $this->assertSame(1, (new MakeMiddlewareCommand())->run([]));
    }
}
