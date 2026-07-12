<?php
declare(strict_types=1);

namespace Tests\Middleware;

use App\Core\Auth as CoreAuth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Middleware\RequireCap;
use PHPUnit\Framework\TestCase;

final class RequireCapTest extends TestCase
{
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        CoreAuth::reset();
        RequireCap::auditUsing(null);
        $_SESSION = [];

        $db = Database::getInstance();
        foreach (['roles', 'permissions', 'role_permissions', 'user_roles'] as $t) {
            $db->pdo()->exec("DROP TABLE IF EXISTS $t");
        }
        $db->pdo()->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, created_at TEXT)');
        $db->pdo()->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, created_at TEXT)');
        $db->pdo()->exec('CREATE TABLE role_permissions (role_id INTEGER, permission_id INTEGER, PRIMARY KEY (role_id, permission_id))');
        $db->pdo()->exec('CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, granted_by INTEGER, granted_at TEXT, PRIMARY KEY (user_id, role_id))');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        CoreAuth::reset();
        RequireCap::auditUsing(null);
        $_SESSION = [];
    }

    private function grant(int $userId, string $roleName, string $permName): void
    {
        $db = Database::getInstance();
        $now = gmdate('Y-m-d H:i:s');
        $db->raw('INSERT INTO roles (name, created_at) VALUES (?, ?)', [$roleName, $now]);
        $db->raw('INSERT INTO permissions (name, created_at) VALUES (?, ?)', [$permName, $now]);
        $roleId = (int) $db->raw('SELECT id FROM roles WHERE name = ?', [$roleName])->fetchColumn();
        $permId = (int) $db->raw('SELECT id FROM permissions WHERE name = ?', [$permName])->fetchColumn();
        $db->raw('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$roleId, $permId]);
        $db->raw('INSERT INTO user_roles (user_id, role_id, granted_at) VALUES (?, ?, ?)', [$userId, $roleId, $now]);
    }

    private function req(string $method = 'GET', string $path = '/admin/x'): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $path;
        return new Request();
    }

    public function testThrowsUnauthorizedWhenNotLoggedIn(): void
    {
        $this->expectException(HttpException::class);
        try {
            (new RequireCap('moderation'))->handle($this->req());
        } catch (HttpException $e) {
            $this->assertSame(401, $e->status);
            throw $e;
        }
    }

    public function testThrowsForbiddenWithoutTheCapability(): void
    {
        CoreAuth::resolveUserUsing(fn (int $id): array => ['id' => $id]);
        $_SESSION['user_id'] = 1;
        $this->expectException(HttpException::class);
        try {
            (new RequireCap('moderation'))->handle($this->req());
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status);
            throw $e;
        }
    }

    public function testPassesWithTheCapability(): void
    {
        CoreAuth::resolveUserUsing(fn (int $id): array => ['id' => $id]);
        $_SESSION['user_id'] = 7;
        $this->grant(7, 'moderator', 'moderation');
        (new RequireCap('moderation'))->handle($this->req());
        $this->addToAssertionCount(1);
    }

    public function testArgOverridesTheConstructorCap(): void
    {
        CoreAuth::resolveUserUsing(fn (int $id): array => ['id' => $id]);
        $_SESSION['user_id'] = 7;
        $this->grant(7, 'finance_manager', 'finance');
        (new RequireCap('moderation'))->handle($this->req(), 'finance');
        $this->addToAssertionCount(1);
    }

    public function testAuditHookFiresOnlyForStateChangingRequests(): void
    {
        CoreAuth::resolveUserUsing(fn (int $id): array => ['id' => $id]);
        $_SESSION['user_id'] = 7;
        $this->grant(7, 'moderator', 'moderation');

        $seen = null;
        RequireCap::auditUsing(function (int $userId, string $cap, string $method, string $path) use (&$seen): void {
            $seen = [$userId, $cap, $method, $path];
        });

        (new RequireCap('moderation'))->handle($this->req('GET', '/admin/reports'));
        $this->assertNull($seen); // GET is not audited

        (new RequireCap('moderation'))->handle($this->req('POST', '/admin/reports/1/resolve'));
        $this->assertSame([7, 'moderation', 'POST', '/admin/reports/1/resolve'], $seen);
    }
}
