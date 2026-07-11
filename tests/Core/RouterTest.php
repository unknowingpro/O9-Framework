<?php
declare(strict_types=1);

namespace App\Middleware {
    use App\Core\Middleware;
    use App\Core\Request;

    /** Test-only middleware proving 'ShortName:arg' resolution (declared inline, not autoloaded). */
    final class RouterTestTrace implements Middleware
    {
        /** @var list<string> */
        public static array $calls = [];

        public function handle(Request $request, ?string $arg = null): void
        {
            self::$calls[] = 'trace:' . ($arg ?? 'null');
        }
    }
}

namespace Tests\Core {

use App\Core\HttpResponse;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Router;
use App\Middleware\RouterTestTrace;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $_GET = $_POST = $_FILES = $_COOKIE = [];
        RouterTestTrace::$calls = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $_POST = $_FILES = $_COOKIE = [];
    }

    private function request(string $method, string $uri): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $uri;
        return new Request();
    }

    /** A handler that records its call, then throws a finished response. */
    private function respond(string $tag): callable
    {
        return function (Request $req, array $params) use ($tag): never {
            throw new HttpResponse(200, ['tag' => $tag, 'params' => $params]);
        };
    }

    /** @return array{0:int,1:mixed} status + payload the dispatch produced */
    private function dispatch(Router $router, Request $request): array
    {
        try {
            $router->dispatch($request);
        } catch (HttpResponse $r) {
            return [$r->status, $r->payload];
        }
        $this->fail('dispatch() returned without producing a response');
    }

    public function testStaticRouteMatches(): void
    {
        $router = new Router();
        $router->get('/files', $this->respond('list'));
        [$status, $payload] = $this->dispatch($router, $this->request('GET', '/files'));
        $this->assertSame(200, $status);
        $this->assertSame('list', $payload['tag'] ?? null);
    }

    public function testParamsCapturedDecodedAndSetOnRequest(): void
    {
        $router = new Router();
        $captured = null;
        $router->get('/files/{uuid}/versions/{v}', function (Request $req, array $params) use (&$captured): never {
            $captured = ['params' => $params, 'fromRequest' => $req->param('uuid')];
            throw new HttpResponse(200, []);
        });
        $req = $this->request('GET', '/files/abc%20def/versions/7');
        $this->dispatch($router, $req);
        $this->assertSame('abc def', $captured['params']['uuid'] ?? null);
        $this->assertSame('7', $captured['params']['v'] ?? null);
        $this->assertSame('abc def', $captured['fromRequest']);
    }

    public function testGroupPrefixAndMiddlewareOrder(): void
    {
        $router = new Router();
        $order = [];
        $mkMw = function (string $name) use (&$order): Middleware {
            return new class($name, $order) implements Middleware {
                /** @param list<string> $order */
                public function __construct(private string $name, private array &$order) {}
                public function handle(Request $request, ?string $arg = null): void
                {
                    $this->order[] = $this->name;
                }
            };
        };
        $router->group('/api/v1', [$mkMw('group')], function (Router $r) use ($mkMw): void {
            $r->get('/things', $this->respond('things'), [$mkMw('route')]);
        });

        [$status] = $this->dispatch($router, $this->request('GET', '/api/v1/things'));
        $this->assertSame(200, $status);
        $this->assertSame(['group', 'route'], $order);
        $this->assertSame('/api/v1/things', $router->routes()[0]['pattern']);
    }

    public function testShortNameMiddlewareWithArg(): void
    {
        $router = new Router();
        $router->get('/x', $this->respond('x'), ['RouterTestTrace:auth']);
        $this->dispatch($router, $this->request('GET', '/x'));
        $this->assertSame(['trace:auth'], RouterTestTrace::$calls);
    }

    public function testAtStringDispatch(): void
    {
        $router = new Router();
        $router->get('/ping', \Tests\Core\Fixtures\PingController::class . '@ping');
        [$status, $payload] = $this->dispatch($router, $this->request('GET', '/ping'));
        $this->assertSame(200, $status);
        $this->assertSame('pong', $payload['tag'] ?? null);
    }

    public function testInvokableControllerDispatch(): void
    {
        $router = new Router();
        $router->get('/invoke', \Tests\Core\Fixtures\InvokableController::class);
        [$status, $payload] = $this->dispatch($router, $this->request('GET', '/invoke'));
        $this->assertSame(200, $status);
        $this->assertSame('invoked', $payload['tag'] ?? null);
    }

    public function testUnknownRouteIs404Json(): void
    {
        $router = new Router();
        $router->get('/exists', $this->respond('ok'));
        [$status, $payload] = $this->dispatch($router, $this->request('GET', '/api/nope'));
        $this->assertSame(404, $status);
        $this->assertFalse($payload['ok'] ?? true);
    }

    public function testMethodMismatchIs405WithAllowHeader(): void
    {
        $router = new Router();
        $router->get('/only-get', $this->respond('ok'));
        $router->post('/only-get', $this->respond('ok'));
        try {
            $router->dispatch($this->request('DELETE', '/only-get'));
            $this->fail('expected 405');
        } catch (HttpResponse $r) {
            $this->assertSame(405, $r->status);
            $this->assertSame('GET, POST', $r->headers['Allow'] ?? '');
        }
    }

    public function testHeadAndOptionsVerbs(): void
    {
        $router = new Router();
        $router->head('/h', $this->respond('head'));
        $router->options('/h', $this->respond('opts'));
        [, $p1] = $this->dispatch($router, $this->request('HEAD', '/h'));
        [, $p2] = $this->dispatch($router, $this->request('OPTIONS', '/h'));
        $this->assertSame('head', $p1['tag'] ?? null);
        $this->assertSame('opts', $p2['tag'] ?? null);
    }
}

}

namespace Tests\Core\Fixtures {

use App\Core\HttpResponse;
use App\Core\Request;

final class PingController
{
    /** @param array<string,string> $params */
    public function ping(Request $request, array $params): never
    {
        throw new HttpResponse(200, ['tag' => 'pong']);
    }
}

final class InvokableController
{
    /** @param array<string,string> $params */
    public function __invoke(Request $request, array $params): never
    {
        throw new HttpResponse(200, ['tag' => 'invoked']);
    }
}

}
