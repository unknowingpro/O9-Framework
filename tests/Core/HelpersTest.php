<?php
declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function testBasePathAndStoragePath(): void
    {
        $this->assertSame(BASE_PATH, base_path());
        $this->assertSame(BASE_PATH . '/config/app.php', base_path('config/app.php'));
        $this->assertSame(BASE_PATH . '/config/app.php', base_path('/config/app.php'));
        $this->assertSame(BASE_PATH . '/storage', storage_path());
        $this->assertSame(BASE_PATH . '/storage/logs', storage_path('logs'));
    }

    public function testConfigDotNotationAndWholeFile(): void
    {
        $this->assertSame('O9', config('app.name'));
        $this->assertSame('HS256', config('app.jwt.algo'));
        $this->assertIsArray(config('app'));
        $this->assertSame('fallback', config('app.no_such_key', 'fallback'));
        $this->assertSame('fallback', config('no_such_file.key', 'fallback'));
    }

    public function testEscapeHelper(): void
    {
        $this->assertSame('&lt;b&gt;&quot;x&quot;&lt;/b&gt;', e('<b>"x"</b>'));
        $this->assertSame('', e(null));
    }

    public function testRandomTokens(): void
    {
        $this->assertSame(64, strlen(str_random()));
        $this->assertSame(16, strlen(str_random(8)));
        $this->assertSame(40, strlen(random_token()));
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', str_random(8));
    }

    public function testUuidShape(): void
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
        $this->assertMatchesRegularExpression($pattern, uuid());
        $this->assertMatchesRegularExpression($pattern, uuid4());
        $this->assertNotSame(uuid(), uuid());
    }

    public function testHumanSize(): void
    {
        $this->assertSame('0 B', human_size(0));
        $this->assertSame('512 B', human_size(512));
        $this->assertSame('1 KB', human_size(1024));
        $this->assertSame('1.5 GB', human_size((int) (1.5 * 1024 ** 3)));
    }

    public function testSafeOriginOr(): void
    {
        $_SERVER['HTTP_HOST'] = 'app.test';
        try {
            $this->assertSame('/dash', safe_origin_or('/dash', '/fallback'));
            $this->assertSame('https://app.test/x', safe_origin_or('https://app.test/x', '/fallback'));
            $this->assertSame('/fallback', safe_origin_or('https://evil.test/x', '/fallback'));
            $this->assertSame('/fallback', safe_origin_or('', '/fallback'));
        } finally {
            unset($_SERVER['HTTP_HOST']);
        }
    }

    public function testSafeBackUsesRefererOnlyWhenSameOrigin(): void
    {
        $_SERVER['HTTP_HOST']    = 'app.test';
        $_SERVER['HTTP_REFERER'] = 'https://evil.test/phish';
        try {
            $this->assertSame('/home', safe_back('/home'));
            $_SERVER['HTTP_REFERER'] = 'https://app.test/list';
            $this->assertSame('https://app.test/list', safe_back('/home'));
        } finally {
            unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_REFERER']);
        }
    }

    public function testFormatNumberFallsBackWithoutIntl(): void
    {
        // Works with or without ext-intl; just assert a sane string comes back.
        $this->assertNotSame('', format_number(1234567));
        $this->assertNotSame('', format_currency(9.5, 'USD'));
        $this->assertNotSame('', format_date(0, 'en', 2, -1));
    }
}
