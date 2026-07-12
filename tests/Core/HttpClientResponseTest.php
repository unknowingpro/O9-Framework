<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\HttpClientResponse;
use PHPUnit\Framework\TestCase;

final class HttpClientResponseTest extends TestCase
{
    public function testStatusAndBody(): void
    {
        $r = new HttpClientResponse(201, '{"a":1}');
        $this->assertSame(201, $r->getStatusCode());
        $this->assertSame('{"a":1}', $r->getBody()->getContents());
    }

    public function testJsonDecodesTheBody(): void
    {
        $r = new HttpClientResponse(200, '{"a":1,"b":"x"}');
        $this->assertSame(['a' => 1, 'b' => 'x'], $r->json());
    }

    public function testJsonReturnsEmptyArrayForInvalidJson(): void
    {
        $r = new HttpClientResponse(200, 'not json');
        $this->assertSame([], $r->json());
    }
}
