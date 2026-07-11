<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\RangeRequest;
use PHPUnit\Framework\TestCase;

final class RangeRequestTest extends TestCase
{
    public function testNoHeaderMeansFullBody(): void
    {
        $r = RangeRequest::parse(null, 1000);
        $this->assertFalse($r->partial);
        $this->assertSame(0, $r->start);
        $this->assertSame(999, $r->end);
        $this->assertSame(1000, $r->length);
        $this->assertNull($r->curlRange());
    }

    public function testExplicitWindow(): void
    {
        $r = RangeRequest::parse('bytes=200-499', 1000);
        $this->assertTrue($r->partial);
        $this->assertSame(200, $r->start);
        $this->assertSame(499, $r->end);
        $this->assertSame(300, $r->length);
        $this->assertSame('200-499', $r->curlRange());
    }

    public function testOpenEndedRange(): void
    {
        $r = RangeRequest::parse('bytes=500-', 1000);
        $this->assertTrue($r->partial);
        $this->assertSame(500, $r->start);
        $this->assertSame(999, $r->end);
        $this->assertSame('500-999', $r->curlRange());
    }

    public function testSuffixRangeIsLastNBytes(): void
    {
        $r = RangeRequest::parse('bytes=-100', 1000);
        $this->assertTrue($r->partial);
        $this->assertSame(900, $r->start);
        $this->assertSame(999, $r->end);
        $this->assertSame(100, $r->length);
    }

    public function testSuffixLargerThanFileServesWholeFileAs206(): void
    {
        $r = RangeRequest::parse('bytes=-5000', 1000);
        $this->assertTrue($r->partial);
        $this->assertSame(0, $r->start);
        $this->assertSame(999, $r->end);
        $this->assertNull($r->curlRange()); // full window → no upstream constraint
    }

    public function testStartBeyondFileSizeIsUnsatisfiable(): void
    {
        $r = RangeRequest::parse('bytes=1000-1200', 1000);
        $this->assertTrue($r->unsatisfiable);
    }

    public function testEndClampedToFileSize(): void
    {
        $r = RangeRequest::parse('bytes=0-99999', 1000);
        $this->assertSame(999, $r->end);
        $this->assertSame(1000, $r->length);
    }

    public function testZeroFileSizeNeverPartial(): void
    {
        $r = RangeRequest::parse('bytes=0-10', 0);
        $this->assertFalse($r->partial);
        $this->assertSame(0, $r->length);
    }

    public function testGarbageHeaderIgnored(): void
    {
        $r = RangeRequest::parse('lines=1-2', 1000);
        $this->assertFalse($r->partial);
    }
}
