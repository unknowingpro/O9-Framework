<?php
declare(strict_types=1);

namespace Tests\Payments;

use App\Payments\Http\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class FakeHttpClientTest extends TestCase
{
    public function testMatchesLongestRegisteredPattern(): void
    {
        $fake = new FakeHttpClient();
        $fake->queue('/payment', ['_status' => 200, 'kind' => 'generic']);
        $fake->queue('/payment/verify', ['_status' => 201, 'kind' => 'specific']);

        $resp = $fake->postJson('https://api.example.com/payment/verify', []);
        $this->assertSame('specific', $resp['kind']);
    }

    public function testUnmatchedUrlReturnsStatusZero(): void
    {
        $fake = new FakeHttpClient();
        $resp = $fake->get('https://nowhere.example.com/x');
        $this->assertSame(0, $resp['_status']);
    }

    public function testRecordsEveryCallByMethod(): void
    {
        $fake = new FakeHttpClient();
        $fake->queue('example.com', ['_status' => 200]);
        $fake->postJson('https://example.com/a', ['x' => 1]);
        $fake->postForm('https://example.com/b', ['y' => 2]);
        $fake->get('https://example.com/c', ['q' => 3]);
        $fake->delete('https://example.com/d');

        $calls = $fake->calls();
        $this->assertSame(['POST', 'POST_FORM', 'GET', 'DELETE'], array_column($calls, 'method'));
        $this->assertSame(['x' => 1], $calls[0]['body']);
    }
}
