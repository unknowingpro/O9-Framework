<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\HttpResponse;
use App\Core\View;
use PHPUnit\Framework\TestCase;

/**
 * View::capture()/render() resolve templates under app/Views/ by a hardcoded
 * path, so these tests write disposable fixture templates into a
 * app/Views/__test__/ subtree and remove them afterward — real sample views
 * (layouts/main, pages/home, components/*) are task 17's job.
 */
final class ViewTest extends TestCase
{
    private string $dir;
    private string $componentsDir;

    protected function setUp(): void
    {
        View::reset();
        $this->dir = base_path('app/Views/__test__');
        $this->componentsDir = base_path('app/Views/components');
        @mkdir($this->dir, 0775, true);
        @mkdir($this->componentsDir, 0775, true);
    }

    protected function tearDown(): void
    {
        View::reset();
        foreach (glob($this->dir . '/*.php') ?: [] as $f) { @unlink($f); }
        foreach (glob($this->componentsDir . '/__test__*.php') ?: [] as $f) { @unlink($f); }
        @rmdir($this->dir);
    }

    private function write(string $relative, string $php): void
    {
        $path = $this->dir . '/' . $relative;
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $php);
    }

    public function testCaptureRendersTemplateWithData(): void
    {
        $this->write('greet.php', '<?php echo "Hello, " . \App\Core\View::e($name) . "!"; ?>');
        $html = View::capture('__test__/greet', ['name' => 'Sara']);
        $this->assertSame('Hello, Sara!', $html);
    }

    public function testCaptureEscapesViaViewE(): void
    {
        $this->write('xss.php', '<?php echo \App\Core\View::e($v); ?>');
        $html = View::capture('__test__/xss', ['v' => '<script>alert(1)</script>']);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testCaptureThrowsForMissingTemplate(): void
    {
        $this->expectException(\RuntimeException::class);
        View::capture('__test__/does-not-exist');
    }

    public function testComponentRendersPropsAndSlot(): void
    {
        file_put_contents($this->componentsDir . '/__test__badge.php', '<?php echo "[" . $label . ":" . $slot . "]"; ?>');
        $html = View::component('__test__badge', ['label' => 'new'], 'inner-html');
        $this->assertSame('[new:inner-html]', $html);
    }

    public function testSectionsCaptureAndYieldWithDefault(): void
    {
        $this->write('sect.php', '<?php \App\Core\View::startSection("title"); echo "Hi"; \App\Core\View::endSection(); ?>');
        View::capture('__test__/sect');
        $this->assertSame('Hi', View::yieldSection('title'));
        $this->assertSame('fallback', View::yieldSection('missing', 'fallback'));
    }

    public function testDirectSectionAssignment(): void
    {
        View::section('x', '<b>bold</b>');
        $this->assertSame('<b>bold</b>', View::yieldSection('x'));
    }

    public function testStacksAccumulateAndFlushInOrder(): void
    {
        View::push('scripts', '<script>a()</script>');
        View::push('scripts', '<script>b()</script>');
        $this->assertSame("<script>a()</script>\n<script>b()</script>", View::stack('scripts'));
        $this->assertSame('', View::stack('empty'));
    }

    public function testRenderWrapsContentInLayoutAndThrowsHttpResponse(): void
    {
        $this->write('page.php', '<?php echo "PAGE:" . $title; ?>');
        $this->write('layout.php', '<?php echo "<html>" . $content . "</html>"; ?>');

        try {
            View::render('__test__/page', ['title' => 'Hi'], '__test__/layout');
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(200, $r->status);
            $this->assertSame('<html>PAGE:Hi</html>', $r->payload);
            $this->assertSame('text/html; charset=utf-8', $r->headers['Content-Type']);
        }
    }

    public function testRenderWithoutLayoutReturnsContentOnly(): void
    {
        $this->write('bare.php', '<?php echo "just-content"; ?>');
        try {
            View::render('__test__/bare', [], null);
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame('just-content', $r->payload);
        }
    }

    public function testRenderStartsFreshSectionAndStackStatePerRender(): void
    {
        View::push('scripts', 'leftover-from-previous-render');
        $this->write('clean.php', '<?php ?>');
        try {
            View::render('__test__/clean', [], null);
        } catch (HttpResponse) {
        }
        $this->assertSame('', View::stack('scripts'));
    }

    public function testRedirectThrowsA302HttpResponse(): void
    {
        try {
            View::redirect('/login');
            $this->fail('expected HttpResponse to be thrown');
        } catch (HttpResponse $r) {
            $this->assertSame(302, $r->status);
            $this->assertSame('/login', $r->headers['Location']);
        }
    }

    public function testEHandlesNullAndScalars(): void
    {
        $this->assertSame('', View::e(null));
        $this->assertSame('42', View::e(42));
        $this->assertSame('&amp;', View::e('&'));
    }
}
