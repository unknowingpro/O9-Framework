<?php
declare(strict_types=1);

namespace Tests\Payments;

use App\Payments\PaymentRouter;
use PHPUnit\Framework\TestCase;

final class PaymentRouterTest extends TestCase
{
    public function testProviderForCurrencyFallsBackToActiveGateway(): void
    {
        // config('payments.currency_provider') ships empty — every currency
        // falls back to PaymentGatewayFactory::active() ('sandbox').
        $this->assertSame('sandbox', PaymentRouter::providerForCurrency('USD'));
        $this->assertSame('sandbox', PaymentRouter::providerForCurrency('EUR'));
    }
}
