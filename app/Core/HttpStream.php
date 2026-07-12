<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Trivial stream wrapper so `$response->getBody()->getContents()` works the
 * same way a PSR-7 stream would, without pulling in a PSR-7 implementation.
 */
final class HttpStream
{
    public function __construct(private string $contents)
    {
    }

    public function getContents(): string
    {
        return $this->contents;
    }

    public function __toString(): string
    {
        return $this->contents;
    }
}
