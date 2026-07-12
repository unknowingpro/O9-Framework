<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpResponse;
use App\Core\Maintenance;
use App\Core\Request;
use App\Services\SettingsService;
use PHPUnit\Framework\TestCase;

final class MaintenanceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        Auth::reset();
        SettingsService::reset();
        $_SESSION = [];

        $db = Database::getInstance();
        $db->pdo()->exec('DROP TABLE IF EXISTS settings');
        $db->pdo()->exec('CREATE TABLE settings (key_name TEXT PRIMARY KEY, value TEXT, updated_by INTEGER, updated_at TEXT NOT NULL)');

        @unlink(Maintenance::flagPath());
    }

    protected function tearDown(): void
    {
        @unlink(Maintenance::flagPath());
        $_SERVER = $this->serverBackup;
        Auth::reset();
        SettingsService::reset();
        $_SESSION = [];
    }

    private function request(string $path, bool $json = false): Request
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ACCEPT'] = $json ? 'application/json' : 'text/html';

        return new Request();
    }

    private function loginAs(int $id, string $roles): void
    {
        Auth::resolveUserUsing(static fn (int $uid): array => ['id' => $uid, 'roles' => $roles]);
        $_SESSION['user_id'] = $id;
    }

    // ── off by default ──────────────────────────────────────────────────────

    public function testOffByDefault(): void
    {
        $this->assertFalse(Maintenance::isOn());
        $this->assertFalse(Maintenance::shouldBlock($this->request('/')));
    }

    // ── flag file switch ────────────────────────────────────────────────────

    public function testFlagFileTurnsItOn(): void
    {
        touch(Maintenance::flagPath());

        $this->assertTrue(Maintenance::isOn());
        $this->assertTrue(Maintenance::shouldBlock($this->request('/')));
    }

    public function testFlagFileFirstLineIsTheMessage(): void
    {
        file_put_contents(Maintenance::flagPath(), "Upgrading the database\nsecond line ignored");

        $this->assertSame('Upgrading the database', Maintenance::message());
    }

    public function testEmptyFlagFileFallsBackToTheDefaultMessage(): void
    {
        touch(Maintenance::flagPath());

        $this->assertSame('Service temporarily unavailable.', Maintenance::message());
    }

    // ── settings switch ─────────────────────────────────────────────────────

    public function testSettingTurnsItOn(): void
    {
        SettingsService::set('maintenance_on', '1');

        $this->assertTrue(Maintenance::isOn());
        $this->assertTrue(Maintenance::shouldBlock($this->request('/')));
    }

    public function testSettingProvidesTheMessage(): void
    {
        SettingsService::set('maintenance_on', '1');
        SettingsService::set('maintenance_msg', 'Back at 5pm');

        $this->assertSame('Back at 5pm', Maintenance::message());
    }

    public function testSettingOffKeepsItOff(): void
    {
        SettingsService::set('maintenance_on', '0');

        $this->assertFalse(Maintenance::isOn());
    }

    /**
     * The whole reason the flag file exists: when the DB is gone the setting
     * cannot be read, and maintenance mode must still work.
     */
    public function testTheFlagFileStillWorksWhenTheSettingsTableIsGone(): void
    {
        Database::getInstance()->pdo()->exec('DROP TABLE settings');
        SettingsService::reset();
        touch(Maintenance::flagPath());

        $this->assertTrue(Maintenance::isOn());
        $this->assertTrue(Maintenance::shouldBlock($this->request('/')));
    }

    // ── who gets through ────────────────────────────────────────────────────

    public function testAdminsBypassSoTheyAreNeverLockedOut(): void
    {
        touch(Maintenance::flagPath());
        $this->loginAs(1, 'admin');

        $this->assertFalse(Maintenance::shouldBlock($this->request('/')));
    }

    public function testANonAdminUserIsStillBlocked(): void
    {
        touch(Maintenance::flagPath());
        $this->loginAs(2, 'member');

        $this->assertTrue(Maintenance::shouldBlock($this->request('/')));
    }

    /** @return list<array{string}> */
    public static function allowedPaths(): array
    {
        return [['/assets/app.css'], ['/admin'], ['/admin/settings'], ['/login'], ['/logout'], ['/auth/token'], ['/health']];
    }

    /** @dataProvider allowedPaths */
    public function testAuthAdminAndAssetPathsStayReachable(string $path): void
    {
        touch(Maintenance::flagPath());

        $this->assertFalse(Maintenance::shouldBlock($this->request($path)), "{$path} must stay reachable");
    }

    public function testAPathThatMerelyStartsWithAnAllowedPrefixIsStillBlocked(): void
    {
        touch(Maintenance::flagPath());

        $this->assertTrue(Maintenance::shouldBlock($this->request('/admin-panel-public')));
    }

    // ── the response ────────────────────────────────────────────────────────

    public function testServeThrowsA503JsonEnvelopeForApiClients(): void
    {
        file_put_contents(Maintenance::flagPath(), 'Upgrading');

        try {
            Maintenance::serve($this->request('/api/v1/x', true));
            $this->fail('expected HttpResponse');
        } catch (HttpResponse $r) {
            $this->assertSame(503, $r->status);
            $this->assertSame('3600', $r->headers['Retry-After']);
            $this->assertSame(
                ['ok' => false, 'data' => null, 'error' => ['code' => 'maintenance', 'message' => 'Upgrading']],
                $r->payload
            );
        }
    }

    public function testServeThrowsA503HtmlPageForBrowsers(): void
    {
        file_put_contents(Maintenance::flagPath(), 'Upgrading');

        try {
            Maintenance::serve($this->request('/'));
            $this->fail('expected HttpResponse');
        } catch (HttpResponse $r) {
            $this->assertSame(503, $r->status);
            $this->assertSame('text/html; charset=utf-8', $r->headers['Content-Type']);
            $this->assertIsString($r->payload);
            $this->assertStringContainsString('503', $r->payload);
            $this->assertStringContainsString('Upgrading', $r->payload);
        }
    }

    public function testTheMessageIsHtmlEscapedInTheFallbackPage(): void
    {
        file_put_contents(Maintenance::flagPath(), '<script>alert(1)</script>');

        try {
            Maintenance::serve($this->request('/'));
            $this->fail('expected HttpResponse');
        } catch (HttpResponse $r) {
            $this->assertIsString($r->payload);
            $this->assertStringNotContainsString('<script>', $r->payload);
            $this->assertStringContainsString('&lt;script&gt;', $r->payload);
        }
    }
}
