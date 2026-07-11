<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $_GET = $_POST = $_FILES = $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $_POST = $_FILES = $_COOKIE = [];
    }

    public function testMethodUriPathAndQueryStripping(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'post';
        $_SERVER['REQUEST_URI']    = '/api/v1/files?page=2';
        $r = new Request();
        $this->assertSame('POST', $r->method());
        $this->assertSame('/api/v1/files', $r->uri());
        $this->assertSame('/api/v1/files', $r->path());
    }

    public function testHeadersAreLowercasedAndAccessible(): void
    {
        $_SERVER['HTTP_X_APP_VERSION'] = '2.1.0';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc.def.ghi';
        $_SERVER['CONTENT_TYPE']       = 'application/json';
        $r = new Request();
        $this->assertSame('2.1.0', $r->header('X-App-Version'));
        $this->assertSame('2.1.0', $r->appVersion());
        $this->assertSame('abc.def.ghi', $r->bearerToken());
        $this->assertSame('application/json', $r->header('content-type'));
    }

    public function testInputPrecedenceJsonThenPostThenGet(): void
    {
        $_GET  = ['k' => 'from-get', 'only_get' => 'g'];
        $_POST = ['k' => 'from-post', 'only_post' => 'p'];
        $r = new Request();
        $this->assertSame('from-post', $r->input('k'));
        $this->assertSame('g', $r->input('only_get'));
        $this->assertSame('fallback', $r->input('missing', 'fallback'));
    }

    public function testBodyParamNeverReadsQueryString(): void
    {
        $_GET  = ['_csrf' => 'attacker-supplied'];
        $_POST = [];
        $r = new Request();
        $this->assertNull($r->bodyParam('_csrf'));
    }

    public function testRouteParamsAndActor(): void
    {
        $r = new Request();
        $this->assertNull($r->param('id'));
        $r->setParams(['id' => '42']);
        $this->assertSame('42', $r->param('id'));
        $this->assertSame(['id' => '42'], $r->params());

        $this->assertNull($r->actor());
        $r->setActor(['id' => 7, 'role' => 'admin']);
        $this->assertSame(7, $r->actor()['id'] ?? null);
    }

    public function testIpIgnoresForwardHeadersFromUntrustedPeer(): void
    {
        $_SERVER['REMOTE_ADDR']          = '203.0.113.10';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1';
        $r = new Request();
        $this->assertSame('203.0.113.10', $r->ip());
    }

    public function testCidrMatching(): void
    {
        $this->assertTrue(Request::ipInCidr('192.168.1.55', '192.168.1.0/24'));
        $this->assertFalse(Request::ipInCidr('192.168.2.55', '192.168.1.0/24'));
        $this->assertTrue(Request::ipInCidr('2400:cb00::1', '2400:cb00::/32'));
        $this->assertFalse(Request::ipInCidr('not-an-ip', '192.168.1.0/24'));
        $this->assertTrue(Request::ipMatchesAny('1.2.3.4', ['1.2.3.4']));
        $this->assertTrue(Request::ipMatchesAny('10.1.2.3', ['192.168.0.0/16', '10.0.0.0/8']));
        $this->assertFalse(Request::ipMatchesAny('8.8.8.8', ['10.0.0.0/8', '']));
    }

    public function testWantsJsonByPathHeaderOrContentType(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v1/x';
        $this->assertTrue((new Request())->wantsJson());

        $_SERVER['REQUEST_URI'] = '/dashboard';
        $this->assertFalse((new Request())->wantsJson());

        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $this->assertTrue((new Request())->wantsJson());
    }

    public function testFileReturnsNullOnUploadError(): void
    {
        $_FILES = [
            'good' => ['name' => 'a.txt', 'error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/x'],
            'bad'  => ['name' => 'b.txt', 'error' => UPLOAD_ERR_PARTIAL, 'tmp_name' => ''],
        ];
        $r = new Request();
        $this->assertNotNull($r->file('good'));
        $this->assertNull($r->file('bad'));
        $this->assertNull($r->file('missing'));
    }
}
