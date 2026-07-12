<?php
declare(strict_types=1);

namespace Tests\Identity;

use App\Core\Database;
use App\Identity\Rbac;
use PHPUnit\Framework\TestCase;

final class RbacTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->db->pdo()->exec('DROP TABLE IF EXISTS roles');
        $this->db->pdo()->exec('DROP TABLE IF EXISTS permissions');
        $this->db->pdo()->exec('DROP TABLE IF EXISTS role_permissions');
        $this->db->pdo()->exec('DROP TABLE IF EXISTS user_roles');
    }

    private function migrate(): void
    {
        $this->db->pdo()->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, created_at TEXT)');
        $this->db->pdo()->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, created_at TEXT)');
        $this->db->pdo()->exec('CREATE TABLE role_permissions (role_id INTEGER, permission_id INTEGER, PRIMARY KEY (role_id, permission_id))');
        $this->db->pdo()->exec('CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, granted_by INTEGER, granted_at TEXT, PRIMARY KEY (user_id, role_id))');
    }

    public function testWithoutMigrationEverythingIsEmptyAndPermissive(): void
    {
        // No roles/user_roles tables — Rbac must no-op, not fatal.
        $this->assertSame([], Rbac::rolesFor(['id' => 1]));
        $this->assertSame([], Rbac::permissionsFor(['id' => 1]));
        $this->assertFalse(Rbac::can(['id' => 1], 'anything'));
        $this->assertSame([], Rbac::allPermissionNames());
        Rbac::assign(1, 'editor'); // must not throw
        Rbac::revoke(1, 'editor'); // must not throw
    }

    public function testAssignAndRolesForAndPermissionsFor(): void
    {
        $this->migrate();
        $now = gmdate('Y-m-d H:i:s');
        $this->db->raw('INSERT INTO roles (name, created_at) VALUES (?, ?)', ['editor', $now]);
        $this->db->raw('INSERT INTO permissions (name, created_at) VALUES (?, ?)', ['posts.edit', $now]);
        $this->db->raw('INSERT INTO permissions (name, created_at) VALUES (?, ?)', ['posts.delete', $now]);
        $roleId = (int) $this->db->raw('SELECT id FROM roles WHERE name = ?', ['editor'])->fetchColumn();
        $editPermId = (int) $this->db->raw('SELECT id FROM permissions WHERE name = ?', ['posts.edit'])->fetchColumn();
        $this->db->raw('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$roleId, $editPermId]);

        Rbac::assign(42, 'editor');
        $this->assertSame(['editor'], Rbac::rolesFor(['id' => 42]));
        $this->assertSame(['posts.edit'], Rbac::permissionsFor(['id' => 42]));
        $this->assertTrue(Rbac::can(['id' => 42], 'posts.edit'));
        $this->assertFalse(Rbac::can(['id' => 42], 'posts.delete'));
    }

    public function testAssignIsIdempotentAndIgnoresUnknownRole(): void
    {
        $this->migrate();
        $this->db->raw('INSERT INTO roles (name, created_at) VALUES (?, ?)', ['editor', gmdate('Y-m-d H:i:s')]);
        Rbac::assign(1, 'editor');
        Rbac::assign(1, 'editor'); // second call must not duplicate/error
        $this->assertSame(['editor'], Rbac::rolesFor(['id' => 1]));

        Rbac::assign(1, 'does-not-exist'); // no-op, no error
        $this->assertSame(['editor'], Rbac::rolesFor(['id' => 1]));
    }

    public function testRevokeRemovesTheAssignment(): void
    {
        $this->migrate();
        $this->db->raw('INSERT INTO roles (name, created_at) VALUES (?, ?)', ['editor', gmdate('Y-m-d H:i:s')]);
        Rbac::assign(1, 'editor');
        Rbac::revoke(1, 'editor');
        $this->assertSame([], Rbac::rolesFor(['id' => 1]));
    }

    public function testAllPermissionNamesReturnsSortedList(): void
    {
        $this->migrate();
        $now = gmdate('Y-m-d H:i:s');
        $this->db->raw('INSERT INTO permissions (name, created_at) VALUES (?, ?)', ['b.perm', $now]);
        $this->db->raw('INSERT INTO permissions (name, created_at) VALUES (?, ?)', ['a.perm', $now]);
        $this->assertSame(['a.perm', 'b.perm'], Rbac::allPermissionNames());
    }

    public function testUserWithNoIdReturnsEmptyRoles(): void
    {
        $this->migrate();
        $this->assertSame([], Rbac::rolesFor([]));
    }
}
