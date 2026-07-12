<?php
declare(strict_types=1);

namespace Tests\Ai;

use App\Ai\AiStreamChunk;
use PHPUnit\Framework\TestCase;

final class AiStreamChunkTest extends TestCase
{
    public function testDefaultChunkHasEmptyDelta(): void
    {
        $c = new AiStreamChunk();
        $this->assertSame('', $c->delta);
        $this->assertNull($c->finishReason);
        $this->assertNull($c->model);
        $this->assertNull($c->inputTokens);
        $this->assertNull($c->outputTokens);
    }

    public function testContentDeltaChunk(): void
    {
        $c = new AiStreamChunk(delta: 'Hello');
        $this->assertSame('Hello', $c->delta);
        $this->assertNull($c->finishReason);
    }

    public function testFirstChunkCarriesModel(): void
    {
        $c = new AiStreamChunk(delta: '', model: 'gpt-4o', inputTokens: 10);
        $this->assertSame('gpt-4o', $c->model);
        $this->assertSame(10, $c->inputTokens);
    }

    public function testFinalChunkCarriesFinishReason(): void
    {
        $c = new AiStreamChunk(delta: ' world', finishReason: 'stop', outputTokens: 5);
        $this->assertSame('stop', $c->finishReason);
        $this->assertSame(5, $c->outputTokens);
    }
}
