<?php
declare(strict_types=1);

namespace Tests\Payments;

use App\Payments\Http\FakeHttpClient;
use App\Payments\PaymentVerifier;
use PHPUnit\Framework\TestCase;

final class PaymentVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        PaymentVerifier::reset();
    }

    protected function tearDown(): void
    {
        PaymentVerifier::reset();
    }

    public function testVerifyWithNoRegisteredProbeReportsUnconfigured(): void
    {
        $result = (new PaymentVerifier())->verify('stripe');
        $this->assertSame('stripe', $result['provider']);
        $this->assertFalse($result['configured']);
        $this->assertNull($result['ok']);
    }

    public function testVerifyUsesRegisteredProbe(): void
    {
        PaymentVerifier::registerProbe('stripe', function () {
            return ['ok' => true, 'detail' => 'HTTP 200'];
        });
        $result = (new PaymentVerifier())->verify('stripe');
        $this->assertTrue($result['configured']);
        $this->assertTrue($result['ok']);
        $this->assertSame('HTTP 200', $result['detail']);
    }

    public function testVerifyAllRunsEveryRegisteredProbe(): void
    {
        PaymentVerifier::registerProbe('a', fn () => ['ok' => true, 'detail' => 'ok']);
        PaymentVerifier::registerProbe('b', fn () => ['ok' => false, 'detail' => 'bad creds']);
        $results = (new PaymentVerifier())->verifyAll();
        $this->assertCount(2, $results);
        $names = array_column($results, 'provider');
        $this->assertSame(['a', 'b'], $names);
    }

    public function testProbeReceivesTheInjectedHttpClient(): void
    {
        $fake = new FakeHttpClient();
        $fake->queue('example.com', ['_status' => 200]);
        $seenClient = null;
        PaymentVerifier::registerProbe('x', function ($http) use (&$seenClient) {
            $seenClient = $http;
            $resp = $http->get('https://example.com/probe');
            return ['ok' => (int) $resp['_status'] === 200, 'detail' => 'ok'];
        });
        $result = (new PaymentVerifier($fake))->verify('x');
        $this->assertSame($fake, $seenClient);
        $this->assertTrue($result['ok']);
    }
}
