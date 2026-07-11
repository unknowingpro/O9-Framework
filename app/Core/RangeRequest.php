<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Parses an HTTP Range request against a known file size into the values a
 * streaming driver needs: the byte window, whether to answer 206, and the
 * upstream CURLOPT_RANGE value (null when the whole file should be fetched).
 *
 * 206 on any matched range; CURLOPT_RANGE only for a strict subset. Suffix
 * ranges (`bytes=-N` = last N bytes) are handled per RFC 7233.
 */
final class RangeRequest
{
    private function __construct(
        public readonly int $start,
        public readonly int $end,
        public readonly int $length,
        public readonly bool $partial,
        private readonly ?string $upstreamRange,
        public readonly bool $unsatisfiable = false,
    ) {}

    public static function parse(?string $rangeHeader, int $fileSize): self
    {
        if ($fileSize <= 0) {
            return new self(0, 0, 0, false, null);
        }
        $full = new self(0, $fileSize - 1, $fileSize, false, null);

        if ($rangeHeader === null || $rangeHeader === ''
            || !preg_match('/bytes=(\d*)-(\d*)/i', $rangeHeader, $m)
            || ($m[1] === '' && $m[2] === '')) {
            return $full;
        }

        if ($m[1] === '') {
            // suffix range: last N bytes
            $n = (int) $m[2];
            if ($n <= 0) {
                return $full;
            }
            $start = max(0, $fileSize - $n);
            $end   = $fileSize - 1;
        } else {
            $start = (int) $m[1];
            $end   = $m[2] === '' ? $fileSize - 1 : (int) $m[2];

            // RFC 7233 §2.1: if first-byte-pos >= file size → 416 Unsatisfiable
            if ($start >= $fileSize) {
                return new self(0, 0, 0, false, null, true);
            }
        }

        $end   = min($end, $fileSize - 1);
        $start = max(0, min($start, $end));

        // 206 on any matched range; constrain the upstream fetch only for a strict subset.
        $subset = ($start > 0 || $end < $fileSize - 1);
        return new self($start, $end, $end - $start + 1, true, $subset ? "{$start}-{$end}" : null);
    }

    public function curlRange(): ?string
    {
        return $this->upstreamRange;
    }

    public function applyHeaders(int $fileSize): void
    {
        if ($this->unsatisfiable) {
            http_response_code(416);
            header("Content-Range: bytes */{$fileSize}");
            return;
        }
        if ($this->partial) {
            http_response_code(206);
            header("Content-Range: bytes {$this->start}-{$this->end}/{$fileSize}");
            header('Content-Length: ' . $this->length);
        } else {
            http_response_code(200);
            if ($fileSize > 0) {
                header('Content-Length: ' . $fileSize);
            }
        }
    }
}
