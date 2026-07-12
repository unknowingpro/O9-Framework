<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\PluralRules;
use PHPUnit\Framework\TestCase;

final class PluralRulesTest extends TestCase
{
    public function testEnglishOneOtherSplit(): void
    {
        $this->assertSame('one', PluralRules::category('en', 1));
        $this->assertSame('other', PluralRules::category('en', 0));
        $this->assertSame('other', PluralRules::category('en', 2));
        $this->assertSame('one', PluralRules::category('en', -1)); // abs() before categorizing
        $this->assertSame('other', PluralRules::category('en', -2));
    }

    public function testNoPluralDistinctionLanguagesAlwaysReturnOther(): void
    {
        foreach (['fa', 'zh', 'id', 'tr', 'ja', 'ko', 'th', 'vi'] as $locale) {
            $this->assertSame('other', PluralRules::category($locale, 0), $locale);
            $this->assertSame('other', PluralRules::category($locale, 1), $locale);
            $this->assertSame('other', PluralRules::category($locale, 5), $locale);
        }
    }

    public function testArabicSixWayCategories(): void
    {
        $this->assertSame('zero', PluralRules::category('ar', 0));
        $this->assertSame('one', PluralRules::category('ar', 1));
        $this->assertSame('two', PluralRules::category('ar', 2));
        $this->assertSame('few', PluralRules::category('ar', 3));
        $this->assertSame('few', PluralRules::category('ar', 10));
        $this->assertSame('many', PluralRules::category('ar', 11));
        $this->assertSame('many', PluralRules::category('ar', 99));
        $this->assertSame('other', PluralRules::category('ar', 100));
        $this->assertSame('other', PluralRules::category('ar', 102));
    }

    public function testRussianOneFewMany(): void
    {
        $this->assertSame('one', PluralRules::category('ru', 1));
        $this->assertSame('one', PluralRules::category('ru', 21));
        $this->assertSame('few', PluralRules::category('ru', 2));
        $this->assertSame('few', PluralRules::category('ru', 3));
        $this->assertSame('few', PluralRules::category('ru', 4));
        $this->assertSame('many', PluralRules::category('ru', 5));
        $this->assertSame('many', PluralRules::category('ru', 11));
        $this->assertSame('many', PluralRules::category('ru', 12));
        $this->assertSame('many', PluralRules::category('ru', 0));
        $this->assertSame('one', PluralRules::category('uk', 1)); // Ukrainian shares the rule
    }

    public function testPolishOneFewMany(): void
    {
        $this->assertSame('one', PluralRules::category('pl', 1));
        $this->assertSame('few', PluralRules::category('pl', 2));
        $this->assertSame('few', PluralRules::category('pl', 4));
        $this->assertSame('many', PluralRules::category('pl', 5));
        $this->assertSame('many', PluralRules::category('pl', 12));
        $this->assertSame('many', PluralRules::category('pl', 0));
    }

    public function testFrenchAndPortugueseTreatZeroAndOneAsOne(): void
    {
        foreach (['fr', 'pt'] as $locale) {
            $this->assertSame('one', PluralRules::category($locale, 0), $locale);
            $this->assertSame('one', PluralRules::category($locale, 1), $locale);
            $this->assertSame('other', PluralRules::category($locale, 2), $locale);
        }
    }

    public function testHindiAndBengaliTreatZeroAndOneAsOne(): void
    {
        foreach (['hi', 'bn'] as $locale) {
            $this->assertSame('one', PluralRules::category($locale, 0), $locale);
            $this->assertSame('one', PluralRules::category($locale, 1), $locale);
            $this->assertSame('other', PluralRules::category($locale, 2), $locale);
        }
    }

    public function testRegionSubtagIsIgnored(): void
    {
        $this->assertSame(PluralRules::category('ar', 3), PluralRules::category('ar-SA', 3));
        $this->assertSame(PluralRules::category('ru', 2), PluralRules::category('ru_RU', 2));
    }
}
