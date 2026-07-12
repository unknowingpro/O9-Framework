<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testGetSetHasForget(): void
    {
        $this->assertFalse(Session::has('user_id'));
        $this->assertNull(Session::get('user_id'));
        $this->assertSame('default', Session::get('user_id', 'default'));

        Session::set('user_id', 42);
        $this->assertTrue(Session::has('user_id'));
        $this->assertSame(42, Session::get('user_id'));

        Session::forget('user_id');
        $this->assertFalse(Session::has('user_id'));
    }

    public function testDestroyClearsSuperglobal(): void
    {
        Session::set('a', 1);
        Session::set('b', 2);
        Session::destroy();
        $this->assertSame([], $_SESSION);
    }

    public function testCsrfTokenIsGeneratedOnceAndStable(): void
    {
        $t1 = Session::csrf();
        $t2 = Session::csrf();
        $this->assertSame($t1, $t2);
        $this->assertSame(64, strlen($t1)); // bin2hex(random_bytes(32))
    }

    public function testCheckCsrfValidatesConstantTime(): void
    {
        $token = Session::csrf();
        $this->assertTrue(Session::checkCsrf($token));
        $this->assertFalse(Session::checkCsrf('wrong-token'));
        $this->assertFalse(Session::checkCsrf(null));
    }

    public function testCheckCsrfFailsWithoutAnIssuedToken(): void
    {
        $this->assertFalse(Session::checkCsrf('anything'));
    }

    public function testFlashIsOneShot(): void
    {
        $this->assertSame([], Session::takeFlash());

        Session::flash('Saved!');
        Session::flash('Careful.', 'warn');

        $flashes = Session::takeFlash();
        $this->assertSame([
            ['msg' => 'Saved!', 'type' => 'ok'],
            ['msg' => 'Careful.', 'type' => 'warn'],
        ], $flashes);

        // Second call gets nothing — flash is consumed.
        $this->assertSame([], Session::takeFlash());
    }
}
