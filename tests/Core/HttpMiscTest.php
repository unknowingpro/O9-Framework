<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\ApiContext;
use App\Core\HttpResponse;
use App\Core\Paginator;
use App\Core\Response;
use PHPUnit\Framework\TestCase;

final class HttpMiscTest extends TestCase
{
    protected function tearDown(): void
    {
        ApiContext::reset();
    }

    public function testApiContextAcceptsValidIncomingIdAndRejectsGarbage(): void
    {
        ApiContext::begin('client-id_1.2');
        $this->assertSame('client-id_1.2', ApiContext::id());

        ApiContext::reset();
        ApiContext::begin("bad id\nwith newline");
        $this->assertNotSame("bad id\nwith newline", ApiContext::id());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', ApiContext::id());
        $this->assertTrue(ApiContext::active());
    }

    public function testApiContextElapsed(): void
    {
        ApiContext::begin(null, microtime(true) - 0.05);
        $this->assertGreaterThanOrEqual(45, ApiContext::elapsedMs());
        $this->assertLessThan(5000, ApiContext::elapsedMs());
    }

    public function testEtagRoundTrip(): void
    {
        $etag = Response::etagFor('{"ok":true}');
        $this->assertTrue(Response::etagSatisfies($etag, $etag));
        $this->assertTrue(Response::etagSatisfies('W/' . $etag, $etag));
        $this->assertTrue(Response::etagSatisfies('"other", ' . $etag, $etag));
        $this->assertTrue(Response::etagSatisfies('*', $etag));
        $this->assertFalse(Response::etagSatisfies('"nope"', $etag));
        $this->assertFalse(Response::etagSatisfies('', $etag));
    }

    public function testPaginatorClampsAndComputesOffsets(): void
    {
        $p = new Paginator(page: 5, perPage: 10, total: 25);
        $this->assertSame(3, $p->pages);
        $this->assertSame(3, $p->page);      // clamped into range
        $this->assertSame(20, $p->offset);
        $this->assertSame(21, $p->from());
        $this->assertSame(25, $p->to());
        $this->assertTrue($p->hasPrev());
        $this->assertFalse($p->hasNext());

        $empty = new Paginator(1, 10, 0);
        $this->assertSame(0, $empty->from());
    }

    public function testPaginatorEnvelopeShapes(): void
    {
        $withTotal = Paginator::envelope(count: 20, page: 1, perPage: 20, total: 57);
        $this->assertTrue($withTotal['has_more']);
        $this->assertSame(57, $withTotal['total']);
        $this->assertSame(3, $withTotal['pages']);

        $cursor = Paginator::envelope(count: 20, page: 2, perPage: 20);
        $this->assertTrue($cursor['has_more']);          // full page → assume more
        $this->assertArrayNotHasKey('total', $cursor);

        $lastPage = Paginator::envelope(count: 3, page: 3, perPage: 20);
        $this->assertFalse($lastPage['has_more']);
    }

    public function testHttpResponseCarriesStatusPayloadHeaders(): void
    {
        $r = new HttpResponse(422, ['ok' => false], ['X-Thing' => '1']);
        $this->assertSame(422, $r->status);
        $this->assertSame(['ok' => false], $r->payload);
        $this->assertSame(['X-Thing' => '1'], $r->headers);
        $this->assertInstanceOf(\RuntimeException::class, $r);
    }

    public function testHttpResponseSendEncodesInvalidUtf8Safely(): void
    {
        $r = new HttpResponse(200, ['title' => "broken \xB1 utf8"]);
        ob_start();
        $r->send();
        $out = (string) ob_get_clean();
        $this->assertNotSame('', $out);
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded); // substitution kept the JSON valid
    }
}
