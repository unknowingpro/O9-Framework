<?php
declare(strict_types=1);

namespace Tests\Ai;

use App\Ai\AiResponse;
use PHPUnit\Framework\TestCase;

final class AiResponseTest extends TestCase
{
    public function testCanBeCreatedWithMinimalArgs(): void
    {
        $r = new AiResponse('Hello', 'gpt-4o');
        $this->assertSame('Hello', $r->content);
        $this->assertSame('gpt-4o', $r->model);
        $this->assertNull($r->inputTokens);
        $this->assertNull($r->outputTokens);
        $this->assertNull($r->finishReason);
    }

    public function testCanBeCreatedWithAllArgs(): void
    {
        $r = new AiResponse('Hello world', 'gpt-4o', 15, 5, 'stop');
        $this->assertSame('Hello world', $r->content);
        $this->assertSame(15, $r->inputTokens);
        $this->assertSame(5,  $r->outputTokens);
        $this->assertSame('stop', $r->finishReason);
    }

    public function testNamedConstructorProducesSameResult(): void
    {
        $r = AiResponse::with('Hi', 'gpt-4o-mini', 10, 20, 'length');
        $this->assertSame('Hi', $r->content);
        $this->assertSame('gpt-4o-mini', $r->model);
        $this->assertSame(10, $r->inputTokens);
        $this->assertSame(20, $r->outputTokens);
        $this->assertSame('length', $r->finishReason);
    }

    public function testInputTokensCanBeNullWhenProviderHidesUsage(): void
    {
        $r = AiResponse::with('Sure', 'gpt-4o');
        $this->assertNull($r->inputTokens);
        $this->assertNull($r->outputTokens);
    }
}
