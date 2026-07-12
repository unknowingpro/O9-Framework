<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\UploadValidator;
use PHPUnit\Framework\TestCase;

final class UploadValidatorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/o9-upload-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->dir);
    }

    public function testPassesWithValidFile(): void
    {
        $result = UploadValidator::validate($this->pngUpload('photo.png'), ['mimes' => ['png'], 'max_size' => 2048]);

        $this->assertTrue($result['valid']);
        $this->assertNotNull($result['data']);
        $this->assertSame('photo.png', $result['data']['name']);
        $this->assertSame('png', $result['data']['ext']);
    }

    public function testFailsOnDisallowedExtension(): void
    {
        $upload = $this->textUpload('doc.exe', 500);
        $result = UploadValidator::validate($upload, ['mimes' => ['jpg', 'png']]);

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['errors']);
    }

    public function testFailsOnExceededMaxSize(): void
    {
        // A ~2 KB upload with max_size = 1 KB should fail
        $upload = $this->textUpload('large.jpg', 2048); // 2048 bytes
        $result = UploadValidator::validate($upload, ['mimes' => ['jpg'], 'max_size' => 1]); // 1 KB max

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('exceeds', implode(' ', $result['errors']));
    }

    public function testPassesOnNullFileWhenNotRequired(): void
    {
        $result = UploadValidator::validate(null, ['required' => false]);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['data']);
        $this->assertEmpty($result['errors']);
    }

    public function testFailsOnNullFileWhenRequired(): void
    {
        $result = UploadValidator::validate(null, ['required' => true]);

        $this->assertFalse($result['valid']);
    }

    public function testFailsOnMissingTmpName(): void
    {
        $result = UploadValidator::validate(['name' => 'x.jpg', 'error' => UPLOAD_ERR_OK]);

        $this->assertFalse($result['valid']);
    }

    public function testFailsOnPhpUploadError(): void
    {
        // Must have a non-empty tmp_name to bypass the "no file" short-circuit
        $upload = ['name' => 'x.jpg', 'tmp_name' => $this->textFile(10), 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 999999];
        $result = UploadValidator::validate($upload);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('server upload limit', $result['errors'][0]);
    }

    public function testFailsOnPartialUpload(): void
    {
        $upload = ['name' => 'x.jpg', 'tmp_name' => $this->textFile(10), 'error' => UPLOAD_ERR_PARTIAL, 'size' => 500];
        $result = UploadValidator::validate($upload);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('partially', $result['errors'][0]);
    }

    public function testMimeSniffRejectsMismatchedContent(): void
    {
        // A real PNG file passed with .exe extension — MIME sniff vs extension
        // mismatch should trigger the content-matches-extension check.
        $png = $this->pngUpload('evil.exe');
        $result = UploadValidator::validate($png, ['mimes' => ['png']]);

        $this->assertFalse($result['valid']);
    }

    public function testMimeSniffCatchesWrongContentForAllowedExtension(): void
    {
        // A plain-text file pretending to be .jpg — extension is allowed but
        // content sniff reveals it's not an image.
        $upload = $this->textUpload('fake.jpg', 100);
        $result = UploadValidator::validate($upload, ['mimes' => ['jpg', 'png']]);

        $this->assertFalse($result['valid']);
    }

    public function testConveniencePassesReturnsBool(): void
    {
        $valid = $this->pngUpload('ok.png');
        $this->assertTrue(UploadValidator::passes($valid, ['mimes' => ['png']]));
        $this->assertFalse(UploadValidator::passes($valid, ['mimes' => ['jpg']]));
    }

    public function testConvenienceValidDataReturnsArrayOrNull(): void
    {
        $valid = $this->pngUpload('ok.png');
        $this->assertNotNull(UploadValidator::validData($valid, ['mimes' => ['png']]));
        $this->assertNull(UploadValidator::validData($valid, ['mimes' => ['exe']]));
    }

    public function testNoMimeFilterAcceptsAnyExtension(): void
    {
        $upload = $this->textUpload('data.bin', 100);
        $result = UploadValidator::validate($upload, ['mimes' => []]);
        $this->assertTrue($result['valid']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Create a fake upload array backed by a real PNG on disk.
     *
     * @return array{name: string, tmp_name: string, error: int, size: int}
     */
    private function pngUpload(string $name): array
    {
        $tmpPath = $this->dir . '/' . bin2hex(random_bytes(8)) . '.png';
        $im      = imagecreatetruecolor(5, 5);
        $this->assertNotFalse($im);
        imagepng($im, $tmpPath);
        imagedestroy($im);

        return [
            'name'     => $name,
            'tmp_name' => $tmpPath,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmpPath),
        ];
    }

    /**
     * Create a fake upload array backed by a text file on disk.
     *
     * @return array{name: string, tmp_name: string, error: int, size: int}
     */
    private function textUpload(string $name, int $sizeBytes): array
    {
        return [
            'name'     => $name,
            'tmp_name' => $this->textFile($sizeBytes),
            'error'    => UPLOAD_ERR_OK,
            'size'     => $sizeBytes,
        ];
    }

    private function textFile(int $sizeBytes): string
    {
        $path = $this->dir . '/' . bin2hex(random_bytes(8)) . '.tmp';
        file_put_contents($path, str_repeat('x', $sizeBytes));
        return $path;
    }
}
