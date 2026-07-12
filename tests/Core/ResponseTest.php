<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\ApiContext;
use App\Core\HttpResponse;
use App\Core\Response;
use PHPUnit\Framework\TestCase;

/**
 * Response::json()/ok()/etc. throw HttpResponse rather than exiting the
 * process (see Response.php's docblock) — the same short-circuit mechanism
 * View::redirect()/Router 404s already use, extended to success responses
 * specifically so these can be unit-tested at all: exit() would otherwise
 * kill the PHPUnit process before any assertion ran.
 */
final class ResponseTest extends TestCase
{
    protected function tearDown(): void
    {
        ApiContext::reset();
        unset($_SERVER['HTTP_IF_NONE_MATCH']);
    }

    public function testOkThrowsA200WithTheStandardEnvelope(): void
    {
        try {
            Response::ok(['x' => 1]);
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(200, $r->status);
            $this->assertSame(['ok' => true, 'data' => ['x' => 1], 'error' => null], json_decode((string) $r->payload, true));
            $this->assertSame('application/json; charset=utf-8', $r->headers['Content-Type']);
        }
    }

    public function testOkIncludesMetaOnlyWhenNonEmpty(): void
    {
        try {
            Response::ok(['x' => 1]);
        } catch (HttpResponse $r) {
            $this->assertArrayNotHasKey('meta', json_decode((string) $r->payload, true));
        }

        try {
            Response::ok(['x' => 1], ['page' => 1]);
        } catch (HttpResponse $r) {
            $this->assertSame(['page' => 1], json_decode((string) $r->payload, true)['meta']);
        }
    }

    public function testCreatedThrowsA201(): void
    {
        try {
            Response::created(['id' => 5]);
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(201, $r->status);
        }
    }

    public function testErrorThrowsTheGivenStatusWithAnErrorEnvelope(): void
    {
        try {
            Response::error('bad_thing', 'Bad thing happened', 422, ['field' => 'required']);
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(422, $r->status);
            $body = json_decode((string) $r->payload, true);
            $this->assertFalse($body['ok']);
            $this->assertSame('bad_thing', $body['error']['code']);
            $this->assertSame(['field' => 'required'], $body['error']['details']);
        }
    }

    public function testNotFoundUnauthorizedForbiddenMapToTheirStatusCodes(): void
    {
        foreach ([['notFound', 404], ['unauthorized', 401], ['forbidden', 403]] as [$method, $status]) {
            try {
                Response::$method();
                $this->fail("expected HttpResponse to be thrown for {$method}()");
            } catch (HttpResponse $r) {
                $this->assertSame($status, $r->status);
            }
        }
    }

    public function testHtmlThrowsWithTheGivenStatusAndBody(): void
    {
        try {
            Response::html('<p>hi</p>', 201);
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(201, $r->status);
            $this->assertSame('<p>hi</p>', $r->payload);
            $this->assertSame('text/html; charset=utf-8', $r->headers['Content-Type']);
        }
    }

    public function testJsonAddsARequestIdHeaderOnlyWhenApiContextIsActive(): void
    {
        try {
            Response::ok(['x' => 1]);
        } catch (HttpResponse $r) {
            $this->assertArrayNotHasKey('X-Request-Id', $r->headers);
        }

        ApiContext::begin('req-123');
        try {
            Response::ok(['x' => 1]);
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame('req-123', $r->headers['X-Request-Id']);
        }
    }

    public function testOkCachedReturns304WhenTheEtagMatches(): void
    {
        $etag = null;
        try {
            Response::okCached(['x' => 1]);
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(200, $r->status);
            $etag = $r->headers['ETag'];
        }

        $_SERVER['HTTP_IF_NONE_MATCH'] = $etag;
        try {
            Response::okCached(['x' => 1]);
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(304, $r->status);
            $this->assertSame('', $r->payload);
        }
    }

    public function testPaginatedWrapsItemsWithAPaginationMetaBlock(): void
    {
        try {
            Response::paginated([1, 2], 1, 2, 10);
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $body = json_decode((string) $r->payload, true);
            $this->assertSame([1, 2], $body['data']);
            $this->assertSame(10, $body['meta']['pagination']['total']);
        }
    }
}
