<?php
declare(strict_types=1);

namespace Tests\Console\Commands;

use App\Console\Commands\MakeModelCommand;
use PHPUnit\Framework\TestCase;

final class MakeModelCommandTest extends TestCase
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

    public function testCreatesAModelWithAGuessedPluralTable(): void
    {
        $path = base_path('app/Models/TestZWidgetModel.php');
        $this->created[] = $path;
        @unlink($path);

        $exit = (new MakeModelCommand())->run(['TestZWidget']);
        $this->assertSame(0, $exit);
        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString("protected string \$table = 'test_z_widgets';", $contents);
        $this->assertStringContainsString('final class TestZWidgetModel extends BaseModel', $contents);
    }

    public function testModelSuffixIsNotDuplicatedInClassOrFilename(): void
    {
        $path = base_path('app/Models/TestZProductModel.php');
        $this->created[] = $path;
        @unlink($path);

        (new MakeModelCommand())->run(['TestZProductModel']);
        $this->assertFileExists($path);
        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString("'test_z_products'", $contents);
        $this->assertStringContainsString('final class TestZProductModel extends BaseModel', $contents);
        $this->assertStringNotContainsString('ModelModel', $contents);
    }

    public function testRefusesToOverwriteAnExistingFile(): void
    {
        $path = base_path('app/Models/TestZExistingModel.php');
        $this->created[] = $path;
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, '<?php // existing');

        $exit = (new MakeModelCommand())->run(['TestZExisting']);
        $this->assertSame(1, $exit);
        $this->assertSame('<?php // existing', file_get_contents($path));
    }

    public function testRequiresAnArgument(): void
    {
        $this->assertSame(1, (new MakeModelCommand())->run([]));
    }
}
