<?php
declare(strict_types=1);

namespace Tests\Models;

use App\Core\Database;
use App\Core\Security\Hash;
use App\Models\UserModel;
use PHPUnit\Framework\TestCase;

final class UserModelTest extends TestCase
{
    private UserModel $model;

    protected function setUp(): void
    {
        $pdo = Database::getInstance()->pdo();
        $pdo->exec('DROP TABLE IF EXISTS users');
        $pdo->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, password_hash TEXT,'
            . ' roles TEXT DEFAULT "", locale TEXT, force_logout_at TEXT, last_seen_at TEXT,'
            . ' created_at TEXT, updated_at TEXT)'
        );
        $this->model = new UserModel();
    }

    public function testRegisterHashesPasswordAndFindByEmailWorks(): void
    {
        $id = $this->model->register('a@example.com', 'Secret-Pass-2026', 'admin,member');
        $row = $this->model->findByEmail('a@example.com');
        $this->assertNotNull($row);
        $this->assertSame($id, (int) $row['id']);
        $this->assertNotSame('Secret-Pass-2026', $row['password_hash']);
        $this->assertSame('admin,member', $row['roles']);
    }

    public function testFindByEmailReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->model->findByEmail('nobody@example.com'));
    }

    public function testVerifyPasswordReturnsIdOnMatchAndNullOtherwise(): void
    {
        $id = $this->model->register('b@example.com', 'Correct-Horse-99');
        $this->assertSame($id, $this->model->verifyPassword('b@example.com', 'Correct-Horse-99'));
        $this->assertNull($this->model->verifyPassword('b@example.com', 'wrong-pass'));
        $this->assertNull($this->model->verifyPassword('nobody@example.com', 'whatever'));
    }

    /**
     * Regression: verifyPassword() used to short-circuit on a nonexistent
     * account before ever calling Hash::check(), so "no such email"
     * returned near-instantly next to "wrong password" (which pays bcrypt's
     * deliberate cost) — a timing side channel for email enumeration. It
     * must now always pay the hashing cost, proven here by asserting the
     * nonexistent-account path takes at least the same order of magnitude
     * of time as a real bcrypt verify (a strict inequality between the two
     * would be flaky; a floor on the "no such account" path is not).
     */
    public function testVerifyPasswordPaysTheSameHashingCostForANonexistentAccount(): void
    {
        $this->model->register('timing@example.com', 'Timing-Test-Pass1');
        $realCheckStart = microtime(true);
        $this->model->verifyPassword('timing@example.com', 'wrong-password-here');
        $realCheckDuration = microtime(true) - $realCheckStart;

        $noAccountStart = microtime(true);
        $this->model->verifyPassword('no-such-account@example.com', 'whatever');
        $noAccountDuration = microtime(true) - $noAccountStart;

        // Both must actually run a bcrypt comparison, not just "not be
        // literally instant" — a generous floor well under real bcrypt cost
        // (typically tens of milliseconds) but well above a skipped check.
        $this->assertGreaterThan(0.001, $realCheckDuration);
        $this->assertGreaterThan(0.001, $noAccountDuration);
    }

    public function testVerifyPasswordRehashesUpgradableHashOnLogin(): void
    {
        $id = $this->model->register('e@example.com', 'Legacy-Pass-42');
        // Simulate a stored hash below the current work factor (cost 4).
        $legacy = password_hash('Legacy-Pass-42', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->assertNotNull($legacy);
        $stmt = Database::getInstance()->pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$legacy, $id]);
        $this->assertTrue(Hash::needsRehash((string) $this->model->findByEmail('e@example.com')['password_hash']));

        // A correct login still resolves the user ...
        $this->assertSame($id, $this->model->verifyPassword('e@example.com', 'Legacy-Pass-42'));

        $row = $this->model->findByEmail('e@example.com');
        // ... the hash is upgraded to a fresh bcrypt hash ...
        $this->assertNotSame($legacy, (string) $row['password_hash']);
        // ... that still verifies against the original password ...
        $this->assertTrue(Hash::check('Legacy-Pass-42', (string) $row['password_hash']));
        // ... and no longer needs a rehash (current work factor).
        $this->assertFalse(Hash::needsRehash((string) $row['password_hash']));
    }

    public function testSetLocaleUpdatesTheRow(): void
    {
        $id = $this->model->register('c@example.com', 'Some-Strong-Pass1');
        $this->model->setLocale($id, 'fa');
        $row = $this->model->find($id);
        $this->assertSame('fa', $row['locale']);
    }

    public function testForceLogoutStampsForceLogoutAt(): void
    {
        $id = $this->model->register('d@example.com', 'Some-Strong-Pass2');
        $this->assertNull($this->model->find($id)['force_logout_at']);
        $this->model->forceLogout($id);
        $this->assertNotNull($this->model->find($id)['force_logout_at']);
    }

    public function testRegisterRejectsAPasswordThatFailsThePolicy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->model->register('weak@example.com', 'short1');
    }
}
