<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\BaseModel;
use App\Core\Security\Hash;
use App\Services\PasswordValidator;

/**
 * Sample model: the `users` table (see setup/database/migrations/007_users.sql).
 * Wire this into the framework's injectable resolver hooks in
 * app/bootstrap.php so Auth/Lang/etc. resolve real users:
 *
 *   Auth::resolveUserUsing(fn (int $id) => (new UserModel())->find($id));
 *   Lang::persistUserLocaleUsing(fn (int $id, string $locale) => (new UserModel())->setLocale($id, $locale));
 */
final class UserModel extends BaseModel
{
    protected string $table = 'users';

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->table()->where('email', '=', $email)->first();
    }

    /**
     * @return int the new user's id
     * @throws \InvalidArgumentException if $password fails PasswordValidator — enforced
     *         here (not just client-side), since this is the actual write path regardless of caller.
     */
    public function register(string $email, string $password, string $roles = ''): int
    {
        $passwordError = PasswordValidator::validate($password);
        if ($passwordError !== null) {
            throw new \InvalidArgumentException($passwordError);
        }

        return $this->create([
            'email'         => $email,
            'password_hash' => Hash::make($password),
            'roles'         => $roles,
        ]);
    }

    /**
     * A fixed, precomputed bcrypt hash with no corresponding real password —
     * used only so verifyPassword() always pays the same hashing cost for a
     * nonexistent account as it does for a wrong password on a real one.
     * Never rotate or derive this from anything account-specific.
     */
    private const DUMMY_HASH = '$2y$10$cQHiyMOxHBdYO2yijmGJuuIqs.4O2MT4sl5LikpVIyWn98NCZDPyS';

    public function verifyPassword(string $email, string $password): ?int
    {
        $user = $this->findByEmail($email);
        // Always run Hash::check(), even when no account matched: PHP's ||
        // short-circuits, so skipping it for a nonexistent email would make
        // that response return near-instantly next to a real "wrong
        // password" response (which pays bcrypt's deliberate cost) — a
        // timing side channel an attacker can use to enumerate which emails
        // are registered without ever seeing a different error message.
        $hash = $user !== null ? (string) $user['password_hash'] : self::DUMMY_HASH;
        $verified = Hash::check($password, $hash);
        if ($user === null || !$verified) {
            return null;
        }
        $id = (int) $user['id'];
        // Transparent upgrade: a successful login with a hash that is below
        // the current work factor (or a legacy non-bcrypt hash migrated here)
        // re-hashes the password with the current algorithm and persists it,
        // so existing accounts move to bcrypt without a forced reset. No-op
        // once every row is on the current work factor.
        if (Hash::needsRehash((string) $user['password_hash'])) {
            $this->updateById($id, ['password_hash' => Hash::make($password)]);
        }
        return $id;
    }

    public function setLocale(int $id, string $locale): void
    {
        $this->updateById($id, ['locale' => $locale]);
    }

    /** Invalidate every existing session/token for this user (see Core\Auth's force-logout epoch). */
    public function forceLogout(int $id): void
    {
        $this->updateById($id, ['force_logout_at' => self::now()]);
    }
}
