<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Units;
use PHPUnit\Framework\TestCase;

final class UnitsTest extends TestCase
{
    public function testIsImperialOnlyForTheExactPreference(): void
    {
        $this->assertTrue(Units::isImperial('imperial'));
        $this->assertFalse(Units::isImperial('metric'));
        $this->assertFalse(Units::isImperial(null));
        $this->assertFalse(Units::isImperial(''));
    }

    public function testMetricWeightIsStoredUnchanged(): void
    {
        $this->assertSame(80.5, Units::toKg(80.5, 'metric'));
        $this->assertSame(80.5, Units::toKg(80.5, null));
    }

    public function testImperialWeightConvertsToKg(): void
    {
        $this->assertSame(45.36, Units::toKg(100.0, 'imperial'));
    }

    public function testWeightRoundTripsThroughStorage(): void
    {
        $kg = Units::toKg(180.0, 'imperial');
        $this->assertSame(180.0, Units::weightDisplay($kg, 'imperial'));
    }

    public function testMetricHeightIsStoredUnchanged(): void
    {
        $this->assertSame(178.0, Units::toCm(178.0, 0.0, 'metric'));
    }

    public function testImperialHeightConvertsFeetAndInchesToCm(): void
    {
        $this->assertSame(177.8, Units::toCm(5.0, 10.0, 'imperial'));
    }

    public function testHeightDisplayMetric(): void
    {
        $this->assertSame(['cm' => 178.0], Units::heightDisplay(178.0, 'metric'));
    }

    public function testHeightDisplayImperial(): void
    {
        $this->assertSame(['ft' => 5, 'in' => 10], Units::heightDisplay(177.8, 'imperial'));
    }

    /** 5'11.6" must carry to 6'0", never render as 5'12". */
    public function testHeightDisplayCarriesTwelveInchesToTheNextFoot(): void
    {
        $this->assertSame(['ft' => 6, 'in' => 0], Units::heightDisplay(182.0, 'imperial'));
    }

    public function testNullsAreSafeForDisplay(): void
    {
        $this->assertNull(Units::weightDisplay(null, 'metric'));
        $this->assertSame(['cm' => 0], Units::heightDisplay(null, 'metric'));
        $this->assertSame(['ft' => 0, 'in' => 0], Units::heightDisplay(null, 'imperial'));
    }

    public function testLabels(): void
    {
        $this->assertSame('kg', Units::weightLabel('metric'));
        $this->assertSame('lb', Units::weightLabel('imperial'));
        $this->assertSame('cm', Units::heightLabel(null));
        $this->assertSame('ft/in', Units::heightLabel('imperial'));
    }
}
