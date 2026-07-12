<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Slug;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function testBasicSlugification(): void
    {
        $this->assertSame('hello-world', Slug::make('Hello World'));
        $this->assertSame('hello-world', Slug::make('  Hello   World!  '));
        $this->assertSame('a-b-c', Slug::make('a/b/c'));
    }

    public function testConsecutiveAndEdgeSeparatorsCollapse(): void
    {
        $this->assertSame('foo-bar', Slug::make('---foo___bar---'));
    }

    public function testEmptyInputYieldsEmptySlug(): void
    {
        $this->assertSame('', Slug::make(''));
        $this->assertSame('', Slug::make('   '));
    }

    /** Non-Latin scripts transliterate to nothing — callers must fall back. */
    public function testNonLatinScriptYieldsEmptySlug(): void
    {
        $this->assertSame('', Slug::make('سلام'));
    }

    public function testTruncationDoesNotLeaveATrailingDash(): void
    {
        $slug = Slug::make('aaaa bbbb cccc', 10);
        $this->assertLessThanOrEqual(10, strlen($slug));
        $this->assertStringEndsNotWith('-', $slug);
    }

    public function testShortIdIsBase36(): void
    {
        $this->assertMatchesRegularExpression('/^[a-z0-9]+$/', Slug::shortId());
        $this->assertNotSame(Slug::shortId(), Slug::shortId());
    }

    public function testShortcodeLengthAndAlphabet(): void
    {
        $code = Slug::shortcode();
        $this->assertSame(11, strlen($code));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{11}$/', $code);
        $this->assertSame(7, strlen(Slug::shortcode(7)));
    }

    public function testUniqueReturnsTheBaseWhenItIsFree(): void
    {
        $this->assertSame('taken-none', Slug::unique('taken-none', static fn (string $s): bool => false));
    }

    public function testUniqueAppendsACounterOnCollision(): void
    {
        $taken = ['post', 'post-2'];
        $slug = Slug::unique('post', static fn (string $s): bool => in_array($s, $taken, true));
        $this->assertSame('post-3', $slug);
    }

    public function testUniqueFallsBackToARandomSuffixWhenEverythingIsTaken(): void
    {
        $slug = Slug::unique('post', static fn (string $s): bool => true, 3);
        $this->assertMatchesRegularExpression('/^post-[a-z0-9]+$/', $slug);
        $this->assertNotSame('post-2', $slug);
        $this->assertNotSame('post-3', $slug);
    }

    public function testUniqueOnEmptyBaseYieldsARandomId(): void
    {
        $slug = Slug::unique('', static fn (string $s): bool => false);
        $this->assertNotSame('', $slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9]+$/', $slug);
    }
}
