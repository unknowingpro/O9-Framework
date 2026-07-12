<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Tiny pagination value object. Clamps the page into range and exposes the
 * SQL offset plus the metadata an admin table/pager needs. Pure data — no DB.
 */
final class Paginator
{
    /** Hard ceiling on page size — a client-supplied per_page must never be able to force an unbounded row scan. */
    public const MAX_PER_PAGE = 100;

    public int $page;
    public int $perPage;
    public int $total;
    public int $pages;
    public int $offset;

    public function __construct(int $page, int $perPage, int $total)
    {
        $this->perPage = min(max(1, $perPage), self::MAX_PER_PAGE);
        $this->total   = max(0, $total);
        $this->pages   = (int) max(1, ceil($this->total / $this->perPage));
        $this->page    = min(max(1, $page), $this->pages);
        $this->offset  = ($this->page - 1) * $this->perPage;
    }

    public function hasPrev(): bool { return $this->page > 1; }
    public function hasNext(): bool { return $this->page < $this->pages; }
    public function from(): int { return $this->total === 0 ? 0 : $this->offset + 1; }
    public function to(): int { return min($this->offset + $this->perPage, $this->total); }

    /** @return array{page:int,per_page:int,total:int,pages:int} */
    public function meta(): array
    {
        return ['page' => $this->page, 'per_page' => $this->perPage, 'total' => $this->total, 'pages' => $this->pages];
    }

    /**
     * Standard API pagination envelope. Always carries page / per_page / count / has_more so a
     * client has a stable shape; adds total + pages only when the caller knows the total (offset/
     * cursor endpoints that don't run a COUNT pass null).
     *
     * @return array<string, int|bool>
     */
    public static function envelope(int $count, int $page, int $perPage, ?int $total = null): array
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), self::MAX_PER_PAGE);
        $meta = [
            'page'     => $page,
            'per_page' => $perPage,
            'count'    => $count,
            'has_more' => $total !== null ? ($page * $perPage < $total) : ($count === $perPage),
        ];
        if ($total !== null) {
            $meta['total'] = $total;
            $meta['pages'] = (int) ceil($total / $perPage);
        }
        return $meta;
    }
}
