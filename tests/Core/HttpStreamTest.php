<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\HttpStream;
use PHPUnit\Framework\TestCase;

final class HttpStreamTest extends TestCase
{
    public function testGetContentsAndToString(): void
    {
        $s = new HttpStream('hello');
        $this->assertSame('hello', $s->getContents());
        $this->assertSame('hello', (string) $s);
    }
}
