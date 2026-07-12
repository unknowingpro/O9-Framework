<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\MediaFilenameHelper;
use PHPUnit\Framework\TestCase;

final class MediaFilenameHelperTest extends TestCase
{
    public function testSanitizeStripsPathComponents(): void
    {
        $this->assertSame('etc-passwd', MediaFilenameHelper::sanitize('../../etc-passwd'));
        $this->assertSame('file.txt', MediaFilenameHelper::sanitize('/absolute/path/file.txt'));
        $this->assertSame('file.txt', MediaFilenameHelper::sanitize('C:\\Users\\x\\file.txt'));
    }

    public function testSanitizeStripsControlCharactersAndNulls(): void
    {
        $this->assertSame('evilname.txt', MediaFilenameHelper::sanitize("evil\x00name.txt"));
    }

    public function testSanitizeCollapsesWhitespace(): void
    {
        $this->assertSame('my file.txt', MediaFilenameHelper::sanitize("my   \t file.txt"));
    }

    public function testSanitizeNeverReturnsEmptyDotOrDotDot(): void
    {
        $this->assertSame('file', MediaFilenameHelper::sanitize(''));
        $this->assertSame('file', MediaFilenameHelper::sanitize('.'));
        $this->assertSame('file', MediaFilenameHelper::sanitize('..'));
    }

    public function testSanitizeTruncatesLongNamesPreservingExtension(): void
    {
        $long = str_repeat('a', 300) . '.txt';
        $result = MediaFilenameHelper::sanitize($long, 20);
        $this->assertLessThanOrEqual(20, strlen($result));
        $this->assertStringEndsWith('.txt', $result);
    }

    public function testExtensionIsLowercased(): void
    {
        $this->assertSame('jpg', MediaFilenameHelper::extension('Photo.JPG'));
        $this->assertSame('', MediaFilenameHelper::extension('no-extension'));
    }

    public function testStemStripsExtension(): void
    {
        $this->assertSame('report', MediaFilenameHelper::stem('report.pdf'));
        $this->assertSame('no-extension', MediaFilenameHelper::stem('no-extension'));
    }

    public function testGuessMimeCommonTypes(): void
    {
        $this->assertSame('image/jpeg', MediaFilenameHelper::guessMime('photo.jpg'));
        $this->assertSame('application/pdf', MediaFilenameHelper::guessMime('doc.PDF'));
        $this->assertSame('application/octet-stream', MediaFilenameHelper::guessMime('mystery.xyz'));
    }

    public function testSafeStoredNamePreservesValidExtension(): void
    {
        $name = MediaFilenameHelper::safeStoredName('My Photo!.JPG');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.jpg$/', $name);
    }

    public function testSafeStoredNameUsesFallbackWhenExtensionIsMissingOrUnsafe(): void
    {
        $name = MediaFilenameHelper::safeStoredName('no-extension-here', 'bin');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.bin$/', $name);

        $noExt = MediaFilenameHelper::safeStoredName('no-extension-here');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $noExt);
    }

    public function testSafeStoredNameRejectsAnOverlongOrWeirdExtension(): void
    {
        $name = MediaFilenameHelper::safeStoredName('file.notarealextension12345');
        $this->assertStringNotContainsString('notarealextension12345', $name);
    }

    public function testSafeStoredNamesAreUnique(): void
    {
        $a = MediaFilenameHelper::safeStoredName('same.txt');
        $b = MediaFilenameHelper::safeStoredName('same.txt');
        $this->assertNotSame($a, $b);
    }
}
