<?php
declare(strict_types=1);

namespace Tests\Subscriptions;

use App\Subscriptions\SubscriptionPlan;
use PHPUnit\Framework\TestCase;

final class SubscriptionPlanTest extends TestCase
{
    public function testPriceCentsReadsConfiguredPrices(): void
    {
        $this->assertSame(999, SubscriptionPlan::priceCents('pro', 'month'));
        $this->assertSame(9999, SubscriptionPlan::priceCents('pro', 'year'));
        $this->assertSame(0, SubscriptionPlan::priceCents('basic', 'month'));
        $this->assertSame(0, SubscriptionPlan::priceCents('pro', 'month', 'EUR')); // no EUR price configured
    }

    public function testIntervalDaysAndValidity(): void
    {
        $this->assertSame(30, SubscriptionPlan::intervalDays('month'));
        $this->assertSame(365, SubscriptionPlan::intervalDays('year'));
        $this->assertSame(30, SubscriptionPlan::intervalDays('bogus')); // unknown -> month default
        $this->assertTrue(SubscriptionPlan::isValidInterval('month'));
        $this->assertTrue(SubscriptionPlan::isValidInterval('year'));
        $this->assertFalse(SubscriptionPlan::isValidInterval('week'));
    }

    public function testIsPaidTier(): void
    {
        $this->assertTrue(SubscriptionPlan::isPaidTier('pro'));
        $this->assertFalse(SubscriptionPlan::isPaidTier('basic'));
        $this->assertFalse(SubscriptionPlan::isPaidTier('no-such-tier'));
    }

    public function testGraceDaysDefault(): void
    {
        $this->assertSame(3, SubscriptionPlan::graceDays());
    }
}
