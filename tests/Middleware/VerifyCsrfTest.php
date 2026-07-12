<?php
declare(strict_types=1);

namespace Tests\Middleware;

use App\Core\HttpException;
use App\Core\HttpResponse;
use App\Core\Request;
use App\Middleware\VerifyCsrf;
use PHPUnit\Framework\TestCase;

final class VerifyCsrfTest extends TestCase
{
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $_SESSION = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_SESSION = [];
        $_POST = [];
    }

    private function req(string $method, string $path): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $path;
        return new Request();
    }

    public function testSafeMethodsPassThroughWithoutAToken(): void
    {
        (new VerifyCsrf())->handle($this->req('GET', '/web/form'));
        (new VerifyCsrf())->handle($this->req('HEAD', '/web/form'));
        (new VerifyCsrf())->handle($this->req('OPTIONS', '/web/form'));
        $this->addToAssertionCount(3);
    }

    public function testApiPathsAreExempt(): void
    {
        (new VerifyCsrf())->handle($this->req('POST', '/api/v1/whatever'));
        $this->addToAssertionCount(1);
    }

    public function testValidTokenPasses(): void
    {
        $_SESSION['_csrf'] = 'token-value';
        $_POST['_csrf'] = 'token-value';
        (new VerifyCsrf())->handle($this->req('POST', '/web/form'));
        $this->addToAssertionCount(1);
    }

    public function testMismatchedTokenOnJsonRequestThrows(): void
    {
        $_SESSION['_csrf'] = 'expected';
        $_POST['_csrf'] = 'wrong';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $this->expectException(HttpException::class);
        try {
            (new VerifyCsrf())->handle($this->req('POST', '/web/form'));
        } catch (HttpException $e) {
            $this->assertSame(419, $e->status);
            throw $e;
        }
    }

    public function testMissingTokenOnNonJsonRequestRedirects(): void
    {
        $_SESSION['_csrf'] = 'expected';
        try {
            (new VerifyCsrf())->handle($this->req('POST', '/web/form'));
            $this->fail('expected a redirect to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(302, $r->status);
        }
    }
}
