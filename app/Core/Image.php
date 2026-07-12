<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Zero-dependency image manipulation backed by PHP's bundled GD extension.
 *
 * Every method works on file paths and writes the result back (or to a new
 * path when $output is specified). Throws RuntimeException on failure.
 *
 * Usage:
 *   Image::resize('upload.jpg', 800, 600, 'thumb.jpg');
 *   Image::crop('upload.jpg', 200, 200, 50, 50);
 *   Image::thumbnail('upload.jpg', 300, 'thumb.jpg');
 *   Image::optimize('upload.jpg');
 *
 * @see https://www.php.net/manual/en/book.image.php
 */
final class Image
{
    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Resize an image to fit within $maxW × $maxH while maintaining the
     * original aspect ratio. The resulting image will be ≤ the given
     * dimensions — it won't be stretched.
     *
     * When $output is null the source file is overwritten.
     *
     * @throws \RuntimeException on missing file, unsupported format, or GD failure
     */
    public static function resize(string $src, int $maxW, int $maxH, ?string $output = null): void
    {
        $info = self::open($src);
        [$srcW, $srcH] = self::calcDimensions($info['w'], $info['h'], $maxW, $maxH);
        $dst = self::createCanvas($srcW, $srcH);

        imagecopyresampled($dst, $info['im'], 0, 0, 0, 0, $srcW, $srcH, $info['w'], $info['h']);
        self::finish($dst, $output ?? $src, $info['ext']);
    }

    /**
     * Crop a rectangle from the image. ($x, $y) is the top-left corner;
     * the crop is $w × $h pixels.
     *
     * When $output is null the source file is overwritten.
     *
     * @throws \RuntimeException
     */
    public static function crop(string $src, int $w, int $h, int $x = 0, int $y = 0, ?string $output = null): void
    {
        $info = self::open($src);
        $dst = self::createCanvas($w, $h);

        imagecopyresampled($dst, $info['im'], 0, 0, $x, $y, $w, $h, $w, $h);
        self::finish($dst, $output ?? $src, $info['ext']);
    }

    /**
     * Create a square thumbnail that fits within $size × $size, cropping to
     * the centre of the image (a "centre crop"). The result is always exactly
     * $size × $size pixels.
     *
     * When $output is null the source file is overwritten.
     *
     * @throws \RuntimeException
     */
    public static function thumbnail(string $src, int $size, ?string $output = null): void
    {
        $info = self::open($src);
        $srcW = $info['w'];
        $srcH = $info['h'];

        // Determine the largest square that fits in the source
        $cropSize = min($srcW, $srcH);
        $x = (int) (($srcW - $cropSize) / 2);
        $y = (int) (($srcH - $cropSize) / 2);

        $dst = self::createCanvas($size, $size);
        imagecopyresampled($dst, $info['im'], 0, 0, $x, $y, $size, $size, $cropSize, $cropSize);
        self::finish($dst, $output ?? $src, $info['ext']);
    }

    /**
     * Optimize a JPEG or PNG image by re-compressing it lossily (JPEG) or
     * losslessly (PNG) and stripping all EXIF/IPTC metadata.
     *
     * Quality:
     *   - JPEG: 0–100 (default 85, same as modern CMS tools)
     *   - PNG: compression level 0–9 (default 6, same as pngquant's default)
     *
     * When $output is null the source file is overwritten.
     *
     * @throws \RuntimeException
     */
    public static function optimize(string $src, int $quality = 85, ?string $output = null): void
    {
        $info = self::open($src);
        $dstPath = $output ?? $src;
        self::write($info['im'], $dstPath, $info['ext'], $quality);
        imagedestroy($info['im']);
    }

    /**
     * Get image dimensions and type without loading the full pixel buffer.
     *
     * @return array{w: int, h: int, type: string} type is 'jpeg', 'png', 'gif', 'webp'
     * @throws \RuntimeException
     */
    public static function info(string $src): array
    {
        if (!is_file($src) || !is_readable($src)) {
            throw new \RuntimeException("Image file not found or not readable: {$src}");
        }
        $dims = @getimagesize($src);
        if ($dims === false) {
            throw new \RuntimeException("Cannot read image dimensions: {$src}");
        }
        return [
            'w'    => $dims[0],
            'h'    => $dims[1],
            'type' => image_type_to_extension($dims[2], false),
        ];
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /**
     * Open an image file and return its handle + metadata.
     *
     * @return array{im: \GdImage, w: int, h: int, ext: string}
     */
    private static function open(string $src): array
    {
        if (!is_file($src)) {
            throw new \RuntimeException("Image file not found: {$src}");
        }

        $dims = @getimagesize($src);
        if ($dims === false) {
            throw new \RuntimeException("Cannot read image: {$src}");
        }

        $ext = image_type_to_extension($dims[2], false);
        $im  = match ($dims[2]) {
            IMAGETYPE_JPEG  => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG   => @imagecreatefrompng($src),
            IMAGETYPE_GIF   => @imagecreatefromgif($src),
            IMAGETYPE_WEBP  => @imagecreatefromwebp($src),
            default         => throw new \RuntimeException("Unsupported image type: {$ext}"),
        };

        if ($im === false) {
            throw new \RuntimeException("Failed to decode image: {$src}");
        }

        // Preserve alpha for PNG/WebP
        if ($ext === 'png' || $ext === 'webp') {
            imagealphablending($im, false);
            imagesavealpha($im, true);
        }

        return ['im' => $im, 'w' => $dims[0], 'h' => $dims[1], 'ext' => $ext];
    }

    /**
     * Calculate new dimensions maintaining aspect ratio.
     *
     * @return array{0: int, 1: int}
     */
    private static function calcDimensions(int $srcW, int $srcH, int $maxW, int $maxH): array
    {
        $ratio = min($maxW / $srcW, $maxH / $srcH, 1.0);
        return [max(1, (int) round($srcW * $ratio)), max(1, (int) round($srcH * $ratio))];
    }

    /** Create a true-colour canvas. */
    private static function createCanvas(int $w, int $h): \GdImage
    {
        $canvas = imagecreatetruecolor($w, $h);
        if ($canvas === false) {
            throw new \RuntimeException("Failed to create {$w}x{$h} canvas");
        }
        // Transparent background for PNG/WebP
        imagesavealpha($canvas, true);
        $trans = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        if ($trans !== false) {
            imagefill($canvas, 0, 0, $trans);
        }
        return $canvas;
    }

    /** Encode and write the GD resource, then free memory. */
    private static function finish(\GdImage $dst, string $path, string $ext): void
    {
        self::write($dst, $path, $ext);
        imagedestroy($dst);
    }

    /**
     * Write GD resource to a file in the given format.
     *
     * @param int $quality JPEG quality (0–100) or PNG compression level (0–9)
     */
    private static function write(\GdImage $im, string $path, string $ext, int $quality = 85): void
    {
        $ok = match ($ext) {
            'jpeg', 'jpg' => imagejpeg($im, $path, min(100, max(0, $quality))),
            'png'         => imagepng($im, $path, min(9, max(0, (int) round((100 - $quality) / 11.1)))),
            'gif'         => imagegif($im, $path),
            'webp'        => imagewebp($im, $path, min(100, max(0, $quality))),
            default       => throw new \RuntimeException("Unsupported output format: {$ext}"),
        };

        if ($ok === false) {
            throw new \RuntimeException("Failed to write image: {$path}");
        }
    }
}
