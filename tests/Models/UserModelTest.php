<?php
declare(strict_types=1);

namespace Tests\Models;

use App\Core\Database;
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
        $id = $this->model->register('a@example.com', 'secret-pass', 'admin,member');
        $row = $this->model->findByEmail('a@example.com');
        $this->assertNotNull($row);
        $this->assertSame($id, (int) $row['id']);
        $this->assertNotSame('secret-pass', $row['password_hash']);
        $this->assertSame('admin,member', $row['roles']);
    }

    public function testFindByEmailReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->model->findByEmail('nobody@example.com'));
    }

    public function testVerifyPasswordReturnsIdOnMatchAndNullOtherwise(): void
    {
        $id = $this->model->register('b@example.com', 'correct-horse');
        $this->assertSame($id, $this->model->verifyPassword('b@example.com', 'correct-horse'));
        $this->assertNull($this->model->verifyPassword('b@example.com', 'wrong-pass'));
        $this->assertNull($this->model->verifyPassword('nobody@example.com', 'whatever'));
    }

    public function testSetLocaleUpdatesTheRow(): void
    {
        $id = $this->model->register('c@example.com', 'x');
        $this->model->setLocale($id, 'fa');
        $row = $this->model->find($id);
        $this->assertSame('fa', $row['locale']);
    }

    public function testForceLogoutStampsForceLogoutAt(): void
    {
        $id = $this->model->register('d@example.com', 'x');
        $this->assertNull($this->model->find($id)['force_logout_at']);
        $this->model->forceLogout($id);
        $this->assertNotNull($this->model->find($id)['force_logout_at']);
    }
}
