<?php
declare(strict_types=1);

namespace Tests\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Middleware\ApiKey;
use PHPUnit\Framework\TestCase;

final class ApiKeyTest extends TestCase
{
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        ApiKey::reset();
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        ApiKey::reset();
        $_GET = [];
        $_POST = [];
    }

    private function req(string $method = 'GET'): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = '/api/v1/x';
        return new Request();
    }

    public function testThrowsWhenNoKeyIsPresent(): void
    {
        $this->expectException(HttpException::class);
        try {
            (new ApiKey())->handle($this->req());
        } catch (HttpException $e) {
            $this->assertSame(401, $e->status);
            throw $e;
        }
    }

    public function testThrowsWithoutARegisteredResolver(): void
    {
        $_SERVER['HTTP_X_API_KEY'] = 'some-key';
        $this->expectException(\RuntimeException::class);
        (new ApiKey())->handle($this->req());
    }

    public function testResolvesFromXApiKeyHeader(): void
    {
        $seen = null;
        ApiKey::resolveUsing(function (string $key) use (&$seen): array {
            $seen = $key;
            return ['id' => 1, 'scopes' => 'read,write'];
        });
        $_SERVER['HTTP_X_API_KEY'] = 'header-key';
        (new ApiKey())->handle($this->req());
        $this->assertSame('header-key', $seen);
        $this->assertSame(['id' => 1, 'scopes' => 'read,write'], ApiKey::current());
    }

    public function testResolvesFromBearerHeaderWhenNoXApiKey(): void
    {
        ApiKey::resolveUsing(fn (string $key): array => ['id' => 1, 'key' => $key]);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer bearer-key';
        (new ApiKey())->handle($this->req());
        $this->assertSame('bearer-key', ApiKey::current()['key']);
    }

    public function testResolvesFromApiKeyQueryParam(): void
    {
        ApiKey::resolveUsing(fn (string $key): array => ['id' => 1, 'key' => $key]);
        $_GET['api_key'] = 'query-key';
        (new ApiKey())->handle($this->req());
        $this->assertSame('query-key', ApiKey::current()['key']);
    }

    public function testResolvesFromUnderscoreKFallback(): void
    {
        ApiKey::resolveUsing(fn (string $key): array => ['id' => 1, 'key' => $key]);
        $_GET['_k'] = 'shorthand-key';
        (new ApiKey())->handle($this->req());
        $this->assertSame('shorthand-key', ApiKey::current()['key']);
    }

    public function testThrowsWhenResolverReturnsNull(): void
    {
        ApiKey::resolveUsing(fn (string $key): ?array => null);
        $_SERVER['HTTP_X_API_KEY'] = 'bad-key';
        $this->expectException(HttpException::class);
        try {
            (new ApiKey())->handle($this->req());
        } catch (HttpException $e) {
            $this->assertSame(401, $e->status);
            throw $e;
        }
    }

    public function testWriteMethodRequiresWriteScopeWhenScopeCheckerIsRegistered(): void
    {
        ApiKey::resolveUsing(fn (string $key): array => ['id' => 1, 'scopes' => 'read']);
        ApiKey::scopeCheckUsing(fn (array $row, string $scope): bool => str_contains((string) $row['scopes'], $scope));
        $_SERVER['HTTP_X_API_KEY'] = 'readonly-key';

        $this->expectException(HttpException::class);
        try {
            (new ApiKey())->handle($this->req('POST'));
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status);
            throw $e;
        }
    }

    public function testReadOnlyKeyStillAllowsGetRequests(): void
    {
        ApiKey::resolveUsing(fn (string $key): array => ['id' => 1, 'scopes' => 'read']);
        ApiKey::scopeCheckUsing(fn (array $row, string $scope): bool => str_contains((string) $row['scopes'], $scope));
        $_SERVER['HTTP_X_API_KEY'] = 'readonly-key';
        (new ApiKey())->handle($this->req('GET'));
        $this->addToAssertionCount(1);
    }

    public function testWriteAllowedWithoutARegisteredScopeChecker(): void
    {
        ApiKey::resolveUsing(fn (string $key): array => ['id' => 1]);
        $_SERVER['HTTP_X_API_KEY'] = 'any-key';
        (new ApiKey())->handle($this->req('POST'));
        $this->addToAssertionCount(1);
    }
}
