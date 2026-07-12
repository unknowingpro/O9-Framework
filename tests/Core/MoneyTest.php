<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Money;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MoneyTest extends TestCase
{
    public function testBaseCurrencyComesFromConfig(): void
    {
        $this->assertSame('USD', Money::base());
    }

    public function testTwoDecimalCurrencyRoundTrips(): void
    {
        $this->assertSame(1234, Money::toMinor(12.34, 'USD'));
        $this->assertSame(12.34, Money::fromMinor(1234, 'USD'));
    }

    /** IRR has no minor unit — the classic `/100` bug scales it by 100x. */
    public function testZeroDecimalCurrencyIsNotDividedByHundred(): void
    {
        $this->assertSame(0, Money::minorExponent('IRR'));
        $this->assertSame(50000, Money::toMinor(50000, 'IRR'));
        $this->assertSame(50000.0, Money::fromMinor(50000, 'IRR'));
    }

    public function testSixDecimalCurrencyKeepsMicroUnits(): void
    {
        $this->assertSame(1500000, Money::toMinor(1.5, 'USDT'));
        $this->assertSame(1.5, Money::fromMinor(1500000, 'USDT'));
    }

    /** Float math must not eat a cent: 0.1 + 0.2 is 0.30000000000000004. */
    public function testRoundingIsExactAtTheCentBoundary(): void
    {
        $this->assertSame(30, Money::toMinor(0.1 + 0.2, 'USD'));
        $this->assertSame(1, Money::toMinor(0.005, 'USD'));   // half-up
        $this->assertSame(0, Money::toMinor(0.004, 'USD'));
    }

    public function testToMinorAcceptsNumericString(): void
    {
        $this->assertSame(999, Money::toMinor('9.99', 'USD'));
    }

    public function testFormatUsesTheCurrencysDecimalPlaces(): void
    {
        $this->assertSame('12.34 USD', Money::format(1234, 'USD'));
        $this->assertSame('50,000 IRR', Money::format(50000, 'IRR'));
        $this->assertSame('1.500000 USDT', Money::format(1500000, 'USDT'));
    }

    public function testSupportedReflectsTheRegistry(): void
    {
        $this->assertTrue(Money::isSupported('USD'));
        $this->assertFalse(Money::isSupported('XYZ'));
        $this->assertContains('IRR', Money::supported());
    }

    public function testUnsupportedCurrencyThrowsInsteadOfGuessingTwoDecimals(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported currency: XYZ');
        Money::toMinor(10.0, 'XYZ');
    }

    public function testFromMinorAlsoRejectsUnsupportedCurrency(): void
    {
        $this->expectException(RuntimeException::class);
        Money::fromMinor(1000, 'XYZ');
    }
}
