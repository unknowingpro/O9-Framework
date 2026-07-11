<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Database;
use App\Core\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    protected function tearDown(): void
    {
        Validator::resetExtensions();
    }

    public function testRequiredHaltsFieldChain(): void
    {
        $r = Validator::check([], ['name' => 'required|min:3']);
        $this->assertFalse($r['valid']);
        $this->assertCount(1, $r['errors']['name']); // min never ran after required failed
    }

    public function testTrimsStringsButNotPasswords(): void
    {
        $r = Validator::check(
            ['name' => '  ada  ', 'password' => '  secret  '],
            ['name' => 'required', 'password' => 'required']
        );
        $this->assertSame('ada', $r['data']['name']);
        $this->assertSame('  secret  ', $r['data']['password']);
    }

    public function testNullableShortCircuits(): void
    {
        $r = Validator::check(['age' => ''], ['age' => 'nullable|int']);
        $this->assertTrue($r['valid']);
        $this->assertNull($r['data']['age']);
    }

    public function testCastsIntNumericBoolean(): void
    {
        $r = Validator::check(
            ['a' => '42', 'b' => '3.5', 'c' => 'yes'],
            ['a' => 'int', 'b' => 'numeric', 'c' => 'boolean']
        );
        $this->assertTrue($r['valid']);
        $this->assertSame(42, $r['data']['a']);
        $this->assertSame(3.5, $r['data']['b']);
        $this->assertTrue($r['data']['c']);
    }

    public function testEmailUrlInRegexDate(): void
    {
        $ok = Validator::check(
            ['e' => 'a@b.co', 'u' => 'https://x.io', 'i' => 'red', 'g' => 'AB-12', 'd' => '2026-07-11 10:00'],
            ['e' => 'email', 'u' => 'url', 'i' => 'in:red,blue', 'g' => 'regex:/^[A-Z]{2}-\d{2}$/', 'd' => 'date']
        );
        $this->assertTrue($ok['valid']);

        $bad = Validator::check(
            ['e' => 'nope', 'i' => 'green', 'd' => 'tomorrow'],
            ['e' => 'email', 'i' => 'in:red,blue', 'd' => 'date']
        );
        $this->assertFalse($bad['valid']);
        $this->assertArrayHasKey('e', $bad['errors']);
        $this->assertArrayHasKey('i', $bad['errors']);
        $this->assertArrayHasKey('d', $bad['errors']); // relative dates rejected
    }

    public function testMinMaxSemanticsPerType(): void
    {
        // String → length; declared int → numeric value; array → count.
        $this->assertFalse(Validator::check(['s' => 'ab'], ['s' => 'min:3'])['valid']);
        $this->assertTrue(Validator::check(['s' => 'abc'], ['s' => 'min:3'])['valid']);
        $this->assertTrue(Validator::check(['n' => '5'], ['n' => 'int|min:3|max:10'])['valid']);
        $this->assertFalse(Validator::check(['n' => '11'], ['n' => 'int|max:10'])['valid']);
        $this->assertFalse(Validator::check(['a' => [1]], ['a' => 'array|min:2'])['valid']);
        // All-digit password measured by LENGTH, not numeric value.
        $this->assertTrue(Validator::check(['password' => '12345678'], ['password' => 'min:8'])['valid']);
    }

    public function testConfirmed(): void
    {
        $ok = Validator::check(
            ['password' => 'x1', 'password_confirmation' => 'x1'],
            ['password' => 'required|confirmed']
        );
        $this->assertTrue($ok['valid']);
        $bad = Validator::check(
            ['password' => 'x1', 'password_confirmation' => 'x2'],
            ['password' => 'required|confirmed']
        );
        $this->assertFalse($bad['valid']);
    }

    public function testUniqueAndExistsDbRules(): void
    {
        $pdo = Database::getInstance()->pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS val_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT)');
        $pdo->exec('DELETE FROM val_users');
        $id = Database::getInstance()->insertGetId('val_users', ['email' => 'taken@x.io']);

        $this->assertFalse(Validator::check(['email' => 'taken@x.io'], ['email' => 'unique:val_users,email'])['valid']);
        $this->assertTrue(Validator::check(['email' => 'free@x.io'], ['email' => 'unique:val_users,email'])['valid']);
        // unique with ignore-id: updating your own row passes.
        $this->assertTrue(Validator::check(['email' => 'taken@x.io'], ['email' => "unique:val_users,email,$id"])['valid']);

        $this->assertTrue(Validator::check(['email' => 'taken@x.io'], ['email' => 'exists:val_users,email'])['valid']);
        $this->assertFalse(Validator::check(['email' => 'ghost@x.io'], ['email' => 'exists:val_users,email'])['valid']);
    }

    public function testCustomExtensionRule(): void
    {
        Validator::extend('slug', static function (string $field, mixed $value, ?string $param): ?string {
            return preg_match('/^[a-z0-9-]+$/', (string) $value) === 1 ? null : 'Bad slug.';
        });
        $this->assertTrue(Validator::check(['s' => 'my-page-1'], ['s' => 'slug'])['valid']);
        $r = Validator::check(['s' => 'My Page!'], ['s' => 'slug']);
        $this->assertFalse($r['valid']);
        $this->assertSame(['Bad slug.'], $r['errors']['s']);
    }
}
