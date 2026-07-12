<?php
declare(strict_types=1);

namespace Tests\Core\Security;

use App\Core\Security\Hash;
use PHPUnit\Framework\TestCase;

final class HashTest extends TestCase
{
    public function testMakeAndCheck(): void
    {
        $hash = Hash::make('correct horse battery staple');
        $this->assertStringStartsWith('$2y$', $hash);
        $this->assertTrue(Hash::check('correct horse battery staple', $hash));
        $this->assertFalse(Hash::check('wrong password', $hash));
    }

    public function testHashesAreSalted(): void
    {
        $this->assertNotSame(Hash::make('same'), Hash::make('same'));
    }

    public function testNeedsRehash(): void
    {
        $this->assertFalse(Hash::needsRehash(Hash::make('pw')));
        // A legacy cost-4 hash must be flagged for upgrade.
        $legacy = password_hash('pw', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->assertTrue(Hash::needsRehash($legacy));
    }
}
