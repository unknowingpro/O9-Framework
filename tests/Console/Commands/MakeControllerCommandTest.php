<?php
declare(strict_types=1);

namespace Tests\Console\Commands;

use App\Console\Commands\MakeControllerCommand;
use PHPUnit\Framework\TestCase;

final class MakeControllerCommandTest extends TestCase
{
    /** @var list<string> */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            @unlink($path);
        }
        $this->created = [];
    }

    public function testCreatesAControllerUnderTheGivenSurface(): void
    {
        $path = base_path('app/Controllers/__Test/PingController.php');
        $this->created[] = $path;
        @unlink($path);

        $exit = (new MakeControllerCommand())->run(['__Test/Ping']);
        $this->assertSame(0, $exit);
        $this->assertFileExists($path);
        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString('namespace App\Controllers\__Test;', $contents);
        $this->assertStringContainsString('final class PingController extends BaseController', $contents);
    }

    public function testControllerSuffixIsNotDuplicated(): void
    {
        $path = base_path('app/Controllers/__Test/PingController.php');
        $this->created[] = $path;
        @unlink($path);

        (new MakeControllerCommand())->run(['__Test/PingController']);
        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString('final class PingController', $contents);
        $this->assertStringNotContainsString('PingControllerController', $contents);
    }

    public function testRefusesToOverwriteAnExistingFile(): void
    {
        $path = base_path('app/Controllers/__Test/PingController.php');
        $this->created[] = $path;
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, '<?php // existing');

        $exit = (new MakeControllerCommand())->run(['__Test/Ping']);
        $this->assertSame(1, $exit);
        $this->assertSame('<?php // existing', file_get_contents($path));
    }

    public function testRequiresAnArgument(): void
    {
        $this->assertSame(1, (new MakeControllerCommand())->run([]));
    }

    public function testDefaultsToWebSurfaceWithoutASlash(): void
    {
        $path = base_path('app/Controllers/Web/StandaloneController.php');
        $this->created[] = $path;
        @unlink($path);

        $exit = (new MakeControllerCommand())->run(['Standalone']);
        $this->assertSame(0, $exit);
        $this->assertFileExists($path);
    }
}
