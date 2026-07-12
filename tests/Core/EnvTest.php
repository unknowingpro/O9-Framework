<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    private string $envFile;

    protected function setUp(): void
    {
        Env::reset();
        $this->envFile = sys_get_temp_dir() . '/o9-env-test-' . getmypid() . '.env';
        file_put_contents($this->envFile, implode("\n", [
            '# comment line',
            'PLAIN=hello',
            'QUOTED_DOUBLE="with spaces"',
            "QUOTED_SINGLE='single # not a comment'",
            'INLINE_COMMENT=value # trailing note',
            'BLANK_WITH_COMMENT=          # this key is intentionally left blank',
            'UNQUOTED_HASH_NO_SPACE=path#fragment',
            'BOOL_TRUE=true',
            'BOOL_PAREN=(false)',
            'NULLED=null',
            'EMPTIED=empty',
            'HASH_URL="https://x.test/#anchor"',
            'NOT_A_PAIR',
        ]));
        Env::load($this->envFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->envFile);
        Env::reset();
    }

    public function testPlainAndQuotedValues(): void
    {
        $this->assertSame('hello', Env::get('PLAIN'));
        $this->assertSame('with spaces', Env::get('QUOTED_DOUBLE'));
        $this->assertSame('single # not a comment', Env::get('QUOTED_SINGLE'));
        $this->assertSame('https://x.test/#anchor', Env::get('HASH_URL'));
    }

    public function testInlineCommentStrippedFromUnquotedValue(): void
    {
        $this->assertSame('value', Env::get('INLINE_COMMENT'));
    }

    /**
     * A `KEY=   # note` line (a documented-but-unset key, the pattern
     * .env.example uses throughout) must resolve to an empty string, not
     * the literal comment text. trim() runs before the inline-comment scan
     * and eats the leading whitespace that would otherwise mark where the
     * comment starts, leaving '#' at position 0 of the trimmed value — that
     * has to count as a comment boundary too, or the whole "# this key..."
     * string becomes the resolved value.
     */
    public function testBlankValueWithTrailingCommentResolvesToEmptyNotTheCommentText(): void
    {
        $this->assertSame('', Env::get('BLANK_WITH_COMMENT', 'SHOULD_NOT_SEE_DEFAULT'));
    }

    /** A '#' not preceded by a space is data, not a comment marker (matches the quoted HASH_URL case). */
    public function testUnquotedHashWithoutPrecedingSpaceIsKeptAsData(): void
    {
        $this->assertSame('path#fragment', Env::get('UNQUOTED_HASH_NO_SPACE'));
    }

    public function testLiteralCoercion(): void
    {
        $this->assertTrue(Env::get('BOOL_TRUE'));
        $this->assertFalse(Env::get('BOOL_PAREN'));
        $this->assertSame('', Env::get('EMPTIED'));
    }

    public function testNullLiteralFallsThroughToDefault(): void
    {
        $this->assertSame('safe-default', Env::get('NULLED', 'safe-default'));
        $this->assertSame('safe-default', Env::get('MISSING_KEY', 'safe-default'));
    }

    public function testServerEnvironmentWins(): void
    {
        putenv('PLAIN=from-server');
        try {
            $this->assertSame('from-server', Env::get('PLAIN'));
        } finally {
            putenv('PLAIN');
        }
    }

    public function testLoadIsIdempotent(): void
    {
        file_put_contents($this->envFile, 'PLAIN=changed');
        Env::load($this->envFile); // second load must be a no-op
        $this->assertSame('hello', Env::get('PLAIN'));
    }

    public function testMissingFileIsSilentlyIgnored(): void
    {
        Env::reset();
        Env::load('/nonexistent/path/.env');
        $this->assertSame('fallback', Env::get('ANYTHING', 'fallback'));
    }
}
