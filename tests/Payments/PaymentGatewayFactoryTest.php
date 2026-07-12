<?php
declare(strict_types=1);

namespace Tests\Payments;

use App\Payments\Gateway\SandboxGateway;
use App\Payments\PaymentGatewayFactory;
use PHPUnit\Framework\TestCase;

final class PaymentGatewayFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        PaymentGatewayFactory::reset();
    }

    public function testMakeDefaultsToSandbox(): void
    {
        $this->assertInstanceOf(SandboxGateway::class, PaymentGatewayFactory::make());
        $this->assertSame('sandbox', PaymentGatewayFactory::active());
    }

    public function testThrowsForUnknownGateway(): void
    {
        $this->expectException(\RuntimeException::class);
        PaymentGatewayFactory::make('nope');
    }

    public function testExtendRegistersACustomGateway(): void
    {
        $fake = new SandboxGateway();
        PaymentGatewayFactory::extend('custom', fn () => $fake);
        $this->assertSame($fake, PaymentGatewayFactory::make('custom'));
    }
}
