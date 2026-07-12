<?php
declare(strict_types=1);

namespace Tests\Console\Commands;

use App\Console\Commands\SeedCommand;
use App\Core\Database;
use App\Core\Seeder;
use PHPUnit\Framework\TestCase;

/**
 * SeedCommand writes via fwrite(STDOUT, …) which bypasses ob_start(),
 * so tests assert on exit codes and database/filesystem side effects.
 */
final class SeedCommandTest extends TestCase
{
    private string $seedDir;

    protected function setUp(): void
    {
        $this->seedDir = sys_get_temp_dir() . '/o9-seed-test-' . bin2hex(random_bytes(4));
        mkdir($this->seedDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->seedDir);
        $pdo = Database::getInstance()->pdo();
        $pdo->exec('DROP TABLE IF EXISTS seed_test');
    }

    public function testNoSeedersDirectoryReturnsZero(): void
    {
        $cmd = new SeedCommand('/nonexistent/path');
        $this->assertSame(0, $cmd->run([]));
    }

    public function testEmptySeedersDirectoryReturnsZero(): void
    {
        $cmd = new SeedCommand($this->seedDir);
        $this->assertSame(0, $cmd->run([]));
    }

    public function testRunsSingleSeederByClassFlag(): void
    {
        $this->writeSeeder($this->seedDir . '/TestSeed.php', 'TestSeed');

        $cmd = new SeedCommand($this->seedDir);
        $exit = $cmd->run(['--class=TestSeed']);
        $this->assertSame(0, $exit);

        $row = Database::getInstance()->raw('SELECT val FROM seed_test')->fetch();
        $this->assertNotFalse($row);
        $this->assertSame('from seeder', $row['val']);
    }

    public function testSkipsClassThatDoesNotExtendSeeder(): void
    {
        $this->writeBadSeeder($this->seedDir . '/NotASeeder.php', 'NotASeeder');

        $cmd = new SeedCommand($this->seedDir);
        // Should exit 0 (not crash) and skip the bad class
        $exit = $cmd->run([]);
        $this->assertSame(0, $exit);
    }

    /** Write a valid seeder file to disk. */
    private function writeSeeder(string $path, string $class): void
    {
        file_put_contents($path, <<<PHP
<?php
namespace App\\Database\\Seeders;

use App\\Core\\Database;
use App\\Core\\Seeder;

class {$class} extends Seeder
{
    public function run(array \$args = []): void
    {
        \$db = Database::getInstance();
        \$db->pdo()->exec('CREATE TABLE IF NOT EXISTS seed_test (id INTEGER PRIMARY KEY, val TEXT)');
        \$db->raw('INSERT INTO seed_test (val) VALUES (?)', ['from seeder']);
    }
}
PHP
        );
        require_once $path;
    }

    /** Write a class that does NOT extend Seeder. */
    private function writeBadSeeder(string $path, string $class): void
    {
        file_put_contents($path, <<<PHP
<?php
namespace App\\Database\\Seeders;

class {$class}
{
    public function run(array \$args = []): void {}
}
PHP
        );
        require_once $path;
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rmdir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
