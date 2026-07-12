<?php
declare(strict_types=1);

namespace Tests\Services\I18n;

use App\Core\Cache\Cache;
use App\Core\Database;
use App\Core\Lang;
use App\Services\I18n\LanguageService;
use PHPUnit\Framework\TestCase;

final class LanguageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        LanguageService::reset();
        Lang::reset();
        Cache::forget('active_languages');
        $this->seedLanguagesTable();
    }

    protected function tearDown(): void
    {
        LanguageService::reset();
        Lang::reset();
        Cache::forget('active_languages');
    }

    private function seedLanguagesTable(): void
    {
        $db = Database::getInstance();
        $db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS languages (
                code TEXT PRIMARY KEY, name TEXT, native TEXT, flag TEXT,
                dir TEXT NOT NULL DEFAULT "ltr", is_active INTEGER NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 0
            )'
        );
        $db->pdo()->exec('DELETE FROM languages');
        $db->raw('INSERT INTO languages (code, name, native, flag, dir, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)',
            ['en', 'English', 'English', '🇬🇧', 'ltr', 1, 1]);
        $db->raw('INSERT INTO languages (code, name, native, flag, dir, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)',
            ['fa', 'Persian', 'فارسی', '🇮🇷', 'rtl', 1, 2]);
        $db->raw('INSERT INTO languages (code, name, native, flag, dir, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)',
            ['de', 'German', 'Deutsch', '🇩🇪', 'ltr', 0, 3]); // inactive — must not appear
    }

    public function testInstanceIsASingletonUntilReset(): void
    {
        $a = LanguageService::getInstance();
        $b = LanguageService::getInstance();
        $this->assertSame($a, $b);
        LanguageService::reset();
        $this->assertNotSame($a, LanguageService::getInstance());
    }

    public function testGetActiveLangsReadsFromDbAndExcludesInactive(): void
    {
        $langs = LanguageService::getInstance()->getActiveLangs();
        $this->assertSame(['en', 'fa'], array_keys($langs));
        $this->assertArrayNotHasKey('de', $langs);
    }

    public function testGetActiveLangsIsCached(): void
    {
        $svc = LanguageService::getInstance();
        $first = $svc->getActiveLangs();
        Database::getInstance()->pdo()->exec('DELETE FROM languages');
        // Still returns the cached result even though the table is now empty.
        $this->assertSame($first, $svc->getActiveLangs());
    }

    public function testSetLangFallsBackToEnglishWhenInactive(): void
    {
        $svc = LanguageService::getInstance();
        $svc->setLang('de'); // inactive
        $this->assertSame('en', $svc->getLang());
        $svc->setLang('fa'); // active
        $this->assertSame('fa', $svc->getLang());
        $this->assertSame('fa', Lang::locale()); // kept in sync with Lang
    }

    public function testTAndTInDelegateToLangWithSprintfArgs(): void
    {
        $svc = LanguageService::getInstance();
        $svc->setLang('en');
        $this->assertSame('Cancel', $svc->t('cancel'));
        $this->assertSame(Lang::raw('no_results', 'fa'), $svc->tIn('fa', 'no_results'));
    }

    public function testGetDelegatesToLangGetWithParamSubstitution(): void
    {
        $svc = LanguageService::getInstance();
        $svc->setLang('en');
        $this->assertSame('No results for "x".', $svc->get('no_results', ['query' => 'x']));
    }

    public function testChoiceAndDunderNDelegateToLangChoice(): void
    {
        $svc = LanguageService::getInstance();
        $svc->setLang('en');
        $this->assertSame('1 item', $svc->choice('items_count', 1));
        $this->assertSame('5 items', $svc->__n('items_count', 5));
    }

    public function testDirReflectsActiveLanguageMetadata(): void
    {
        $svc = LanguageService::getInstance();
        $svc->setLang('fa');
        $this->assertSame('rtl', $svc->dir());
        $svc->setLang('en');
        $this->assertSame('ltr', $svc->dir());
    }

    public function testLangKeyboardPairsRowsOfTwo(): void
    {
        $rows = LanguageService::getInstance()->langKeyboard();
        $this->assertCount(1, $rows); // 2 active langs → one row of 2
        $this->assertCount(2, $rows[0]);
        $this->assertSame('set_lang:en', $rows[0][0]['callback_data']);
    }

    public function testPickPrefersCurrentLangThenEnglishThenFirst(): void
    {
        $svc = LanguageService::getInstance();
        $svc->setLang('fa');
        $translations = [
            ['lang' => 'en', 'title' => 'Hello'],
            ['lang' => 'de', 'title' => 'Hallo'],
        ];
        // No 'fa' entry — falls back to 'en'.
        $this->assertSame('Hello', $svc->pick($translations));

        $noEnglish = [['lang' => 'de', 'title' => 'Hallo']];
        $this->assertSame('Hallo', $svc->pick($noEnglish));

        $this->assertSame('fallback', $svc->pick([], 'title', 'fallback'));
    }

    public function testFlushCacheDropsTheCacheAndLangMessages(): void
    {
        $svc = LanguageService::getInstance();
        $svc->getActiveLangs(); // warm the cache
        $svc->flushCache();
        $this->assertNull(Cache::get('active_languages'));
        // getActiveLangs() re-reads from the DB after a flush.
        $this->assertSame(['en', 'fa'], array_keys($svc->getActiveLangs()));
    }
}
