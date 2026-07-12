<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Paginator;
use PHPUnit\Framework\TestCase;

final class PaginatorTest extends TestCase
{
    public function testComputesOffsetAndPageCountForANormalPage(): void
    {
        $p = new Paginator(2, 10, 25);
        $this->assertSame(2, $p->page);
        $this->assertSame(10, $p->perPage);
        $this->assertSame(3, $p->pages);
        $this->assertSame(10, $p->offset);
        $this->assertTrue($p->hasPrev());
        $this->assertTrue($p->hasNext());
    }

    public function testClampsPageBelowOneUpToOne(): void
    {
        $p = new Paginator(0, 10, 25);
        $this->assertSame(1, $p->page);
    }

    public function testClampsPageAboveTheLastPageDownToTheLastPage(): void
    {
        $p = new Paginator(999, 10, 25);
        $this->assertSame(3, $p->page);
    }

    public function testClampsAnOversizedPerPageDownToTheMaximum(): void
    {
        // A client-supplied per_page must never be able to force an unbounded row scan.
        $p = new Paginator(1, 1_000_000, 25);
        $this->assertSame(Paginator::MAX_PER_PAGE, $p->perPage);
    }

    public function testClampsAZeroOrNegativePerPageUpToOne(): void
    {
        $p = new Paginator(1, 0, 25);
        $this->assertSame(1, $p->perPage);
    }

    public function testEnvelopeAlsoClampsAnOversizedPerPage(): void
    {
        $meta = Paginator::envelope(count: 5, page: 1, perPage: 1_000_000, total: 25);
        $this->assertSame(Paginator::MAX_PER_PAGE, $meta['per_page']);
    }
}
