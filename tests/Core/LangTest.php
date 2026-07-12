<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Auth;
use App\Core\Lang;
use PHPUnit\Framework\TestCase;

final class LangTest extends TestCase
{
    protected function setUp(): void
    {
        Lang::reset();
        Auth::reset();
        $_GET = [];
        $_COOKIE = [];
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    protected function tearDown(): void
    {
        Lang::reset();
        Auth::reset();
        $_GET = [];
        $_COOKIE = [];
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    public function testRegistryAndSupportedComeFromConfigLocales(): void
    {
        $supported = Lang::supported();
        $this->assertContains('en', $supported);
        $this->assertContains('ar', $supported);
        $this->assertCount(21, $supported);
        $this->assertSame('ltr', Lang::registry()['en']['dir']);
    }

    public function testDefaultLocaleWhenNothingElseMatches(): void
    {
        $this->assertSame('en', Lang::locale());
    }

    public function testSetLocaleRejectsUnsupportedCode(): void
    {
        Lang::setLocale('xx-not-real');
        $this->assertSame('en', Lang::locale());
        Lang::setLocale('fa');
        $this->assertSame('fa', Lang::locale());
    }

    public function testQueryStringWinsOverEverythingElse(): void
    {
        $_GET['lang'] = 'de';
        $_COOKIE['lang'] = 'fr';
        $this->assertSame('de', Lang::locale());
    }

    public function testCookieIsUsedWhenNoQueryOrUser(): void
    {
        $_COOKIE['lang'] = 'ru';
        $this->assertSame('ru', Lang::locale());
    }

    public function testLoggedInUsersSavedLocaleBeatsCookie(): void
    {
        Auth::resolveUserUsing(fn (int $id): array => ['id' => $id, 'locale' => 'ja']);
        $_SESSION['user_id'] = 1;
        $_COOKIE['lang'] = 'ru';
        $this->assertSame('ja', Lang::locale());
        unset($_SESSION['user_id']);
    }

    public function testAcceptLanguageHeaderBestMatch(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr-FR;q=0.5,de;q=0.9,en;q=0.1';
        $this->assertSame('de', Lang::locale()); // highest q among supported
    }

    public function testAcceptLanguageIgnoresUnsupportedRanges(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'xx-XX;q=0.9,pt;q=0.5';
        $this->assertSame('pt', Lang::locale());
    }

    public function testRememberSetsLocaleAndPersistsViaHook(): void
    {
        $seen = null;
        Lang::persistUserLocaleUsing(function (int $id, string $locale) use (&$seen): void {
            $seen = [$id, $locale];
        });
        Auth::resolveUserUsing(fn (int $id): array => ['id' => $id]);
        $_SESSION['user_id'] = 7;

        $this->assertTrue(Lang::remember('es'));
        $this->assertSame('es', Lang::locale());
        $this->assertSame([7, 'es'], $seen);
        unset($_SESSION['user_id']);
    }

    public function testRememberRejectsUnsupportedLocale(): void
    {
        $this->assertFalse(Lang::remember('not-a-real-locale'));
    }

    public function testPersistUserIsNoOpWithoutARegisteredHook(): void
    {
        Auth::resolveUserUsing(fn (int $id): array => ['id' => $id]);
        $_SESSION['user_id'] = 3;
        // Must not throw even though no hook is registered.
        $this->assertTrue(Lang::remember('it'));
        unset($_SESSION['user_id']);
    }

    public function testMetaHelpersReadFromTheLocaleRegistry(): void
    {
        $this->assertSame('rtl', Lang::direction('ar'));
        $this->assertSame('ltr', Lang::direction('en'));
        $this->assertSame('ar@calendar=islamic', Lang::intlId('ar'));
        $this->assertSame('islamic', Lang::calendar('ar'));
        $this->assertSame('arabic', Lang::fontGroup('ar'));
        $this->assertSame('SAR', Lang::currency('ar'));
        $this->assertSame('gregorian', Lang::calendar('en'));
    }

    public function testGetSubstitutesParamsAndFallsBackToKey(): void
    {
        $this->assertSame('Cancel', Lang::get('cancel', [], 'en'));
        $this->assertSame('No results for "foo".', Lang::get('no_results', ['query' => 'foo'], 'en'));
        $this->assertSame('Nonexistent, right', Lang::get('Nonexistent, right', [], 'en'));
    }

    public function testGetFallsBackToConfiguredFallbackLocale(): void
    {
        // A locale with no app/Lang/{code}.php file at all must fall back to
        // app.fallback_locale (en), not to the key itself.
        $this->assertSame(Lang::get('back', [], 'en'), Lang::get('back', [], 'xx-no-such-file'));
    }

    public function testHasReflectsPresenceInLocaleOrFallback(): void
    {
        $this->assertTrue(Lang::has('back', 'en'));
        $this->assertFalse(Lang::has('this.key.does.not.exist', 'en'));
    }

    public function testRawReturnsUnsubstitutedString(): void
    {
        $this->assertSame('Cancel', Lang::raw('cancel', 'en'));
        $this->assertNull(Lang::raw('this.key.does.not.exist', 'en'));
    }

    public function testChoiceUsesCldrCategoryWithPercentPlaceholders(): void
    {
        $this->assertSame('1 item', Lang::choice('items_count', 1, [], 'en'));
        $this->assertSame('5 items', Lang::choice('items_count', 5, [], 'en'));
    }

    public function testChoiceArabicSixWayCategories(): void
    {
        // ar.items_count: zero/one/two/few/many/other all distinct.
        $this->assertStringContainsString('لا عناصر', Lang::choice('items_count', 0, [], 'ar'));
        $this->assertStringContainsString('عنصر', Lang::choice('items_count', 1, [], 'ar'));
        $this->assertStringContainsString('عنصران', Lang::choice('items_count', 2, [], 'ar'));
    }

    public function testChoiceFallsBackToKeyWhenMissingEverywhere(): void
    {
        $this->assertSame('no.such.plural.key', Lang::choice('no.such.plural.key', 3, [], 'en'));
    }

    public function testFlushClearsCachedMessageArrays(): void
    {
        Lang::get('cancel', [], 'en'); // warms the cache
        Lang::flush();
        // Still resolves correctly after a flush (re-reads from disk).
        $this->assertSame('Cancel', Lang::get('cancel', [], 'en'));
    }
}
