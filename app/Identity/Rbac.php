<?php
declare(strict_types=1);

namespace App\Identity;

use App\Core\Database;

/**
 * Role-Based Access Control. A user's permissions aggregate from their
 * assigned roles: user_roles -> roles -> role_permissions -> permissions.
 * No-ops (empty roles/permissions) when the RBAC tables aren't migrated yet,
 * so introducing this service never breaks an app that hasn't run the
 * migration.
 */
final class Rbac
{
    private static function db(): Database
    {
        return Database::getInstance();
    }

    private static function ready(): bool
    {
        return self::db()->tableExists('roles') && self::db()->tableExists('user_roles');
    }

    /**
     * Role names assigned to a user.
     *
     * @param array<string, mixed> $user
     * @return list<string>
     */
    public static function rolesFor(array $user): array
    {
        $uid = (int) ($user['id'] ?? 0);
        if ($uid <= 0 || !self::ready()) {
            return [];
        }
        $rows = self::db()->raw(
            'SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?',
            [$uid]
        )->fetchAll();
        return array_values(array_map(static fn (array $r): string => (string) $r['name'], $rows));
    }

    /**
     * All permission names a user holds (union over their roles).
     *
     * @param array<string, mixed> $user
     * @return list<string>
     */
    public static function permissionsFor(array $user): array
    {
        $roles = self::rolesFor($user);
        if (!self::ready() || $roles === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($roles), '?'));
        $rows = self::db()->raw(
            "SELECT DISTINCT p.name
               FROM roles r
               JOIN role_permissions rp ON rp.role_id = r.id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE r.name IN ($place)",
            $roles
        )->fetchAll();
        return array_values(array_unique(array_map(static fn (array $r): string => (string) $r['name'], $rows)));
    }

    /**
     * Does the user hold $permission?
     *
     * @param array<string, mixed> $user
     */
    public static function can(array $user, string $permission): bool
    {
        return in_array($permission, self::permissionsFor($user), true);
    }

    /** @return list<string> */
    public static function allPermissionNames(): array
    {
        if (!self::db()->tableExists('permissions')) {
            return [];
        }
        return array_values(array_map(
            static fn (array $r): string => (string) $r['name'],
            self::db()->raw('SELECT name FROM permissions ORDER BY name')->fetchAll()
        ));
    }

    public static function assign(int $userId, string $roleName, ?int $byUserId = null): void
    {
        if (!self::ready()) {
            return;
        }
        $roleId = (int) (self::db()->raw('SELECT id FROM roles WHERE name = ?', [$roleName])->fetchColumn() ?: 0);
        if ($roleId === 0) {
            return;
        }
        $exists = self::db()->raw('SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ?', [$userId, $roleId])->fetchColumn();
        if (!$exists) {
            self::db()->raw(
                'INSERT INTO user_roles (user_id, role_id, granted_by, granted_at) VALUES (?, ?, ?, ?)',
                [$userId, $roleId, $byUserId, gmdate('Y-m-d H:i:s')]
            );
        }
    }

    public static function revoke(int $userId, string $roleName): void
    {
        if (!self::ready()) {
            return;
        }
        self::db()->raw(
            'DELETE FROM user_roles WHERE user_id = ? AND role_id = (SELECT id FROM roles WHERE name = ?)',
            [$userId, $roleName]
        );
    }
}
