<?php
declare(strict_types=1);

namespace Tests\Controllers\Api;

use App\Controllers\Api\HealthController;
use App\Core\HttpResponse;
use App\Core\Request;
use PHPUnit\Framework\TestCase;

final class HealthControllerTest extends TestCase
{
    public function testIndexReturnsAnOkStatusEnvelope(): void
    {
        try {
            (new HealthController())->index(new Request());
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(200, $r->status);
            $body = json_decode((string) $r->payload, true);
            $this->assertTrue($body['ok']);
            $this->assertSame('ok', $body['data']['status']);
        }
    }

    public function testReadyReturns200WhenTheDatabaseIsReachable(): void
    {
        try {
            (new HealthController())->ready(new Request());
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(200, $r->status);
            $body = json_decode((string) $r->payload, true);
            $this->assertTrue($body['data']['checks']['db']);
        }
    }
}
