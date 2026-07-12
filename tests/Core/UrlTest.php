<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Url;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/o9-url-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/nested', 0775, true);
        file_put_contents($this->root . '/photo.jpg', 'x');
        file_put_contents($this->root . '/nested/deep.jpg', 'x');
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/nested/deep.jpg');
        @unlink($this->root . '/photo.jpg');
        @rmdir($this->root . '/nested');
        @rmdir($this->root);
    }

    // ── safe() ──────────────────────────────────────────────────────────────

    public function testSafeAcceptsHttpAndHttps(): void
    {
        $this->assertSame('https://example.com/a.jpg', Url::safe('https://example.com/a.jpg'));
        $this->assertSame('http://example.com/a.jpg', Url::safe('http://example.com/a.jpg'));
    }

    public function testSafeRejectsHostileSchemes(): void
    {
        $this->assertNull(Url::safe('javascript:alert(1)'));
        $this->assertNull(Url::safe('data:text/html;base64,PHNjcmlwdD4='));
        $this->assertNull(Url::safe('file:///etc/passwd'));
        $this->assertNull(Url::safe('JavaScript:alert(1)'));
    }

    public function testSafeRejectsSchemelessAndHostlessInput(): void
    {
        $this->assertNull(Url::safe('/just/a/path'));
        $this->assertNull(Url::safe('example.com/a.jpg'));
        $this->assertNull(Url::safe('https://'));
    }

    public function testSafeRejectsControlCharacterSmuggling(): void
    {
        $this->assertNull(Url::safe("java\nscript:alert(1)"));
        $this->assertNull(Url::safe("https://example.com/\x00.jpg"));
    }

    public function testSafeRejectsEmpty(): void
    {
        $this->assertNull(Url::safe(''));
        $this->assertNull(Url::safe('   '));
        $this->assertNull(Url::safe(null));
    }

    // ── mediaDiskPath() ─────────────────────────────────────────────────────

    public function testResolvesAPathOnlyMediaUrlIntoTheRoot(): void
    {
        $this->assertSame(
            realpath($this->root) . '/photo.jpg',
            Url::mediaDiskPath('/media/photo.jpg', $this->root)
        );
    }

    public function testResolvesAnAbsoluteUrlByItsPath(): void
    {
        $this->assertSame(
            realpath($this->root) . '/nested/deep.jpg',
            Url::mediaDiskPath('https://cdn.example.com/media/nested/deep.jpg', $this->root)
        );
    }

    public function testAPathNotYetOnDiskStillResolvesInsideTheRoot(): void
    {
        $this->assertSame(
            realpath($this->root) . '/not-created-yet.jpg',
            Url::mediaDiskPath('/media/not-created-yet.jpg', $this->root)
        );
    }

    public function testTraversalIsRefused(): void
    {
        $this->assertNull(Url::mediaDiskPath('/media/../../../../etc/passwd', $this->root));
        $this->assertNull(Url::mediaDiskPath('/media/a/../../etc/passwd', $this->root));
    }

    /** Percent-encoded traversal must be caught after a single decode. */
    public function testPercentEncodedTraversalIsRefused(): void
    {
        $this->assertNull(Url::mediaDiskPath('/media/%2e%2e%2f%2e%2e%2fetc/passwd', $this->root));
    }

    /** parse_url() rewrites a raw NUL to '_', so it must be caught before parsing. */
    public function testNulByteIsRefused(): void
    {
        $this->assertNull(Url::mediaDiskPath("/media/photo.jpg\0.txt", $this->root));
    }

    public function testPercentEncodedNulByteIsRefused(): void
    {
        $this->assertNull(Url::mediaDiskPath('/media/photo.jpg%00.txt', $this->root));
    }

    public function testEmptyAndPrefixOnlyInputIsRefused(): void
    {
        $this->assertNull(Url::mediaDiskPath('', $this->root));
        $this->assertNull(Url::mediaDiskPath(null, $this->root));
        $this->assertNull(Url::mediaDiskPath('/media/', $this->root));
    }

    public function testMissingRootIsRefusedRatherThanGuessed(): void
    {
        $this->assertNull(Url::mediaDiskPath('/media/photo.jpg', $this->root . '/does-not-exist'));
    }
}
