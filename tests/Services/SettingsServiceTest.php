<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Core\Database;
use App\Services\SettingsService;
use PHPUnit\Framework\TestCase;

final class SettingsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $pdo = Database::getInstance()->pdo();
        $pdo->exec('DROP TABLE IF EXISTS settings');
        $pdo->exec('CREATE TABLE settings (key_name TEXT PRIMARY KEY, value TEXT, updated_by INTEGER, updated_at TEXT)');
        SettingsService::reset();
    }

    protected function tearDown(): void
    {
        SettingsService::reset();
    }

    public function testGetReturnsDefaultWhenUnset(): void
    {
        $this->assertSame('fallback', SettingsService::get('missing', 'fallback'));
    }

    public function testSetThenGetRoundTripsScalarsAndArrays(): void
    {
        SettingsService::set('maintenance_message', 'back soon');
        SettingsService::set('feature_flags', ['beta' => true, 'items' => [1, 2, 3]]);

        $this->assertSame('back soon', SettingsService::get('maintenance_message'));
        $this->assertSame(['beta' => true, 'items' => [1, 2, 3]], SettingsService::get('feature_flags'));
    }

    public function testSetOverwritesAnExistingKey(): void
    {
        SettingsService::set('mode', 'a');
        SettingsService::set('mode', 'b');
        $this->assertSame('b', SettingsService::get('mode'));

        $row = Database::getInstance()->table('settings')->where('key_name', '=', 'mode')->first();
        $this->assertNotNull($row);
    }

    public function testForgetRemovesTheKey(): void
    {
        SettingsService::set('temp', 'x');
        SettingsService::forget('temp');
        $this->assertNull(SettingsService::get('temp'));
        $this->assertNull(Database::getInstance()->table('settings')->where('key_name', '=', 'temp')->first());
    }

    public function testAllReturnsEveryStoredSetting(): void
    {
        SettingsService::set('a', 1);
        SettingsService::set('b', 2);
        $this->assertSame(['a' => 1, 'b' => 2], SettingsService::all());
    }

    public function testEnsureLoadedDegradesGracefullyWithoutTheTable(): void
    {
        Database::getInstance()->pdo()->exec('DROP TABLE settings');
        SettingsService::reset();
        $this->assertSame('fallback', SettingsService::get('anything', 'fallback'));
        $this->assertSame([], SettingsService::all());
    }
}
