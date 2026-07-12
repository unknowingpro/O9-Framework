<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\HttpClient;
use PHPUnit\Framework\TestCase;

/**
 * HttpClient's SSRF/validation guards throw before any network I/O, so
 * they're testable without a live endpoint. Successful request/response
 * flows require real network access and aren't covered here, matching the
 * rest of the framework's curl-based drivers.
 */
final class HttpClientTest extends TestCase
{
    public function testRejectsAnEmptyUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new HttpClient())->get('');
    }

    public function testRejectsAnEmptyMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new HttpClient())->request('', 'https://example.com');
    }

    public function testRejectsNonHttpSchemes(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('non-http(s)');
        (new HttpClient())->get('ftp://example.com/file');
    }

    public function testRejectsUrlWithNoHost(): void
    {
        $this->expectException(\RuntimeException::class);
        (new HttpClient())->get('http:///path-only');
    }

    public function testRejectsLoopbackIpLiteral(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('private/reserved');
        (new HttpClient())->get('http://127.0.0.1/admin');
    }

    public function testRejectsPrivateRfc1918IpLiteral(): void
    {
        $this->expectException(\RuntimeException::class);
        (new HttpClient())->get('http://10.0.0.5/internal');
    }

    public function testRejectsCloudMetadataAddress(): void
    {
        $this->expectException(\RuntimeException::class);
        (new HttpClient())->get('http://169.254.169.254/latest/meta-data/');
    }

    public function testConvenienceMethodsExistAndValidateTheirUrl(): void
    {
        $http = new HttpClient();
        foreach (['post', 'put', 'delete'] as $method) {
            try {
                $http->$method('ftp://example.com');
                $this->fail("expected $method() to reject a non-http(s) URL");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('non-http(s)', $e->getMessage());
            }
        }
    }
}
