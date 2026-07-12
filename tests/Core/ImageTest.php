<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Image;
use PHPUnit\Framework\TestCase;

final class ImageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/o9-image-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->dir);
    }

    public function testInfoReturnsDimensionsAndType(): void
    {
        $png = $this->createPng(200, 150);
        $info = Image::info($png);
        $this->assertSame(200, $info['w']);
        $this->assertSame(150, $info['h']);
        $this->assertSame('png', $info['type']);
    }

    public function testResizeMaintainsAspectRatio(): void
    {
        $src  = $this->createPng(400, 200);
        $out  = $this->dir . '/resized.png';
        Image::resize($src, 100, 100, $out);

        $info = Image::info($out);
        // 400×200 fits into 100×100 with aspect → 100×50
        $this->assertSame(100, $info['w']);
        $this->assertSame(50, $info['h']);
    }

    public function testResizeDoesNotUpscale(): void
    {
        $src  = $this->createPng(50, 30);
        $out  = $this->dir . '/no-up.png';
        Image::resize($src, 200, 200, $out);

        $info = Image::info($out);
        $this->assertSame(50, $info['w']);
        $this->assertSame(30, $info['h']);
    }

    public function testResizeOverwritesSourceWhenNoOutputGiven(): void
    {
        $src = $this->createPng(100, 80);
        Image::resize($src, 50, 50);
        $info = Image::info($src);
        $this->assertSame(50, $info['w']);
        $this->assertLessThanOrEqual(50, $info['h']);
    }

    public function testCropExtractsSubRegion(): void
    {
        $src  = $this->createPng(300, 200);
        $out  = $this->dir . '/cropped.png';
        Image::crop($src, 100, 100, 50, 50, $out);

        $info = Image::info($out);
        $this->assertSame(100, $info['w']);
        $this->assertSame(100, $info['h']);
    }

    public function testThumbnailIsExactSquare(): void
    {
        $src  = $this->createPng(400, 300);
        $out  = $this->dir . '/thumb.png';
        Image::thumbnail($src, 120, $out);

        $info = Image::info($out);
        $this->assertSame(120, $info['w']);
        $this->assertSame(120, $info['h']);
    }

    public function testThumbnailOnPortraitImageCropsToCentre(): void
    {
        $src  = $this->createPng(200, 500);
        $out  = $this->dir . '/pthumb.png';
        Image::thumbnail($src, 100, $out);

        $info = Image::info($out);
        $this->assertSame(100, $info['w']);
        $this->assertSame(100, $info['h']);
    }

    public function testOptimizePreservesImageAndDimensions(): void
    {
        $src = $this->dir . '/optim.jpg';
        $im  = imagecreatetruecolor(150, 100);
        $this->assertNotFalse($im);
        imagefilledrectangle($im, 0, 0, 149, 99, imagecolorallocate($im, 200, 100, 50));
        imagejpeg($im, $src, 90);
        imagedestroy($im);

        Image::optimize($src, 60);
        $info = Image::info($src);
        $this->assertSame(150, $info['w']);
        $this->assertSame(100, $info['h']);
        $this->assertSame('jpeg', $info['type']);
    }

    public function testJpegRoundTrip(): void
    {
        $src = $this->dir . '/test.jpg';
        $im  = imagecreatetruecolor(80, 60);
        $this->assertNotFalse($im);
        imagejpeg($im, $src, 90);
        imagedestroy($im);

        $info = Image::info($src);
        $this->assertSame(80, $info['w']);
        $this->assertSame(60, $info['h']);
        $this->assertSame('jpeg', $info['type']);
    }

    public function testWebpRoundTrip(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('WebP support not compiled into PHP GD');
        }

        $src = $this->dir . '/test.webp';
        $im  = imagecreatetruecolor(64, 64);
        $this->assertNotFalse($im);
        imagewebp($im, $src, 80);
        imagedestroy($im);

        $info = Image::info($src);
        $this->assertSame(64, $info['w']);
        $this->assertSame(64, $info['h']);
        $this->assertSame('webp', $info['type']);

        $thumb = $this->dir . '/thumb.webp';
        Image::thumbnail($src, 32, $thumb);
        $tInfo = Image::info($thumb);
        $this->assertSame(32, $tInfo['w']);
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');
        Image::info('/no/such/file.jpg');
    }

    public function testUnsupportedFormatThrows(): void
    {
        $path = $this->dir . '/test.bmp';
        file_put_contents($path, 'FAKEBMP');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('read image');
        Image::info($path);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function createPng(int $w, int $h): string
    {
        $path = $this->dir . "/{$w}x{$h}.png";
        $im   = imagecreatetruecolor($w, $h);
        $this->assertNotFalse($im);
        $red = imagecolorallocate($im, 200, 50, 50);
        imagefill($im, 0, 0, $red);
        imagepng($im, $path);
        imagedestroy($im);
        return $path;
    }
}
