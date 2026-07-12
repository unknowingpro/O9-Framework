<?php
declare(strict_types=1);

namespace Tests\I18n;

use App\Core\Database;
use App\Core\Lang;
use App\I18n\Translatable;
use PHPUnit\Framework\TestCase;

final class TranslatableTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->db->pdo()->exec('DROP TABLE IF EXISTS content_translations');
        $this->db->pdo()->exec(
            'CREATE TABLE content_translations (
                id INTEGER PRIMARY KEY AUTOINCREMENT, entity_type TEXT, entity_id INTEGER,
                field TEXT, locale TEXT, value TEXT, created_at TEXT, updated_at TEXT,
                UNIQUE (entity_type, entity_id, field, locale)
            )'
        );
        Lang::reset();
        Lang::setLocale('fa');
    }

    protected function tearDown(): void
    {
        Lang::reset();
    }

    public function testTextFallsBackToBaseWhenNoTranslationExists(): void
    {
        $this->assertSame('Base Title', Translatable::text('product', 1, 'Base Title'));
    }

    public function testTextReturnsBaseForTheFallbackLocaleWithoutAQuery(): void
    {
        Lang::setLocale('en'); // app.fallback_locale default is 'en'
        Translatable::put('product', 1, 'name', 'en', 'Should never be stored');
        // isBaseLocale short-circuits put() too — verify nothing was written.
        $count = (int) $this->db->raw('SELECT COUNT(*) c FROM content_translations')->fetch()['c'];
        $this->assertSame(0, $count);
        $this->assertSame('Base', Translatable::text('product', 1, 'Base'));
    }

    public function testPutThenTextResolvesTheTranslation(): void
    {
        Translatable::put('product', 1, 'name', 'fa', 'محصول');
        $this->assertSame('محصول', Translatable::text('product', 1, 'Base', 'name'));
    }

    public function testPutWithEmptyValueRemovesExistingTranslation(): void
    {
        Translatable::put('product', 1, 'name', 'fa', 'محصول');
        Translatable::put('product', 1, 'name', 'fa', '   '); // blank after trim
        $this->assertSame('Base', Translatable::text('product', 1, 'Base', 'name', 'fa'));
    }

    public function testPutIgnoresUnsupportedLocale(): void
    {
        Translatable::put('product', 1, 'name', 'xx-not-a-locale', 'ignored');
        $this->assertSame('Base', Translatable::text('product', 1, 'Base', 'name', 'fa'));
    }

    public function testPutUpsertsOnRepeatedCalls(): void
    {
        Translatable::put('product', 1, 'name', 'fa', 'first');
        Translatable::put('product', 1, 'name', 'fa', 'second');
        $count = (int) $this->db->raw('SELECT COUNT(*) c FROM content_translations')->fetch()['c'];
        $this->assertSame(1, $count);
        $this->assertSame('second', Translatable::text('product', 1, 'Base', 'name', 'fa'));
    }

    public function testMapBatchResolvesOnlyIdsWithTranslations(): void
    {
        Translatable::put('product', 1, 'name', 'fa', 'یک');
        Translatable::put('product', 3, 'name', 'fa', 'سه');
        $map = Translatable::map('product', [1, 2, 3], 'name', 'fa');
        $this->assertSame(['یک', 'سه'], [$map[1], $map[3]]);
        $this->assertArrayNotHasKey(2, $map);
    }

    public function testMapReturnsEmptyForBaseLocale(): void
    {
        $this->assertSame([], Translatable::map('product', [1, 2], 'name', 'en'));
    }

    public function testForFieldListsEveryLocaleForAnEntity(): void
    {
        Translatable::put('product', 1, 'name', 'fa', 'یک');
        Translatable::put('product', 1, 'name', 'ar', 'واحد');
        $all = Translatable::forField('product', 1, 'name');
        $this->assertSame(['ar' => 'واحد', 'fa' => 'یک'], $all);
    }

    public function testPutManyAppliesEachLocaleInTheMap(): void
    {
        Translatable::putMany('product', 1, 'name', ['fa' => 'یک', 'ar' => 'واحد']);
        $this->assertSame('یک', Translatable::text('product', 1, 'Base', 'name', 'fa'));
        $this->assertSame('واحد', Translatable::text('product', 1, 'Base', 'name', 'ar'));
    }

    public function testPurgeRemovesEveryFieldForAnEntity(): void
    {
        Translatable::put('product', 1, 'name', 'fa', 'یک');
        Translatable::put('product', 1, 'description', 'fa', 'توضیحات');
        Translatable::purge('product', 1);
        $count = (int) $this->db->raw('SELECT COUNT(*) c FROM content_translations WHERE entity_id = 1')->fetch()['c'];
        $this->assertSame(0, $count);
    }
}
