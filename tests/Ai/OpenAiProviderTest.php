<?php
declare(strict_types=1);

namespace Tests\Ai;

use App\Ai\AiStreamChunk;
use App\Ai\OpenAiProvider;
use PHPUnit\Framework\TestCase;

final class OpenAiProviderTest extends TestCase
{
    // ── chat() tests ────────────────────────────────────────────────────────

    public function testChatReturnsContentAndUsageFromResponse(): void
    {
        $poster = fn (string $url, array $body, array $headers): array => [
            'status' => 200,
            'body'   => json_encode([
                'id'      => 'chatcmpl-abc',
                'model'   => 'gpt-4o',
                'choices' => [
                    ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hello!'], 'finish_reason' => 'stop'],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ], JSON_THROW_ON_ERROR),
        ];

        $provider = new OpenAiProvider(
            ['api_key' => 'sk-test', 'base_url' => 'https://api.openai.com/v1'],
            null,
            $poster,
        );

        $response = $provider->chat('gpt-4o', [['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('Hello!', $response->content);
        $this->assertSame('gpt-4o', $response->model);
        $this->assertSame(10, $response->inputTokens);
        $this->assertSame(5,  $response->outputTokens);
        $this->assertSame('stop', $response->finishReason);
    }

    public function testChatWithMinimalResponseHasNullUsage(): void
    {
        $poster = fn (string $url, array $body, array $headers): array => [
            'status' => 200,
            'body'   => json_encode([
                'id'      => 'chatcmpl-xyz',
                'model'   => 'gpt-4o-mini',
                'choices' => [
                    ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Sure']],
                ],
            ], JSON_THROW_ON_ERROR),
        ];

        $provider = new OpenAiProvider(
            ['api_key' => 'sk-test', 'base_url' => 'https://api.openai.com/v1'],
            null,
            $poster,
        );

        $response = $provider->chat('gpt-4o-mini', [['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('Sure', $response->content);
        $this->assertNull($response->inputTokens);
        $this->assertNull($response->outputTokens);
        $this->assertNull($response->finishReason);
    }

    public function testChatThrowsOnHttpError(): void
    {
        $poster = fn (string $url, array $body, array $headers): array => [
            'status' => 401,
            'body'   => json_encode(['error' => ['message' => 'Incorrect API key']], JSON_THROW_ON_ERROR),
        ];

        $provider = new OpenAiProvider(
            ['api_key' => 'sk-bad', 'base_url' => 'https://api.openai.com/v1'],
            null,
            $poster,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('401');
        $this->expectExceptionMessage('Incorrect API key');
        $provider->chat('gpt-4o', []);
    }

    public function testChatHandlesMalformedJsonResponseGracefully(): void
    {
        $poster = fn (string $url, array $body, array $headers): array => [
            'status' => 200,
            'body'   => 'not-json-at-all',
        ];

        $provider = new OpenAiProvider(
            ['api_key' => 'sk-test', 'base_url' => 'https://api.openai.com/v1'],
            null,
            $poster,
        );

        $result = $provider->chat('gpt-4o', []);
        $this->assertSame('', $result->content);
    }

    public function testChatPassesOptionsIntoRequestBody(): void
    {
        $captured = null;
        $poster = function (string $url, array $body, array $headers) use (&$captured): array {
            $captured = $body;
            return [
                'status' => 200,
                'body'   => json_encode([
                    'id' => 'x', 'model' => 'gpt-4o',
                    'choices' => [['index' => 0, 'message' => ['content' => 'ok']]],
                ], JSON_THROW_ON_ERROR),
            ];
        };

        $provider = new OpenAiProvider(
            ['api_key' => 'sk-test', 'base_url' => 'https://api.openai.com/v1'],
            null,
            $poster,
        );

        $provider->chat('gpt-4o', [['role' => 'user', 'content' => 'Hi']], [
            'temperature' => 0.7,
            'max_tokens'  => 100,
            'top_p'       => 0.9,
            'stop'        => ['\n'],
        ]);

        $this->assertSame('gpt-4o', $captured['model']);
        $this->assertSame(0.7, $captured['temperature']);
        $this->assertSame(100, $captured['max_tokens']);
        $this->assertSame(0.9, $captured['top_p']);
        $this->assertSame(['\n'], $captured['stop']);
    }

    public function testNameIsOpenai(): void
    {
        $provider = new OpenAiProvider(
            ['api_key' => 'sk-test', 'base_url' => 'https://api.openai.com/v1'],
        );
        $this->assertSame('openai', $provider->name());
    }

    public function testConstructorThrowsOnEmptyApiKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('api_key');
        new OpenAiProvider(['api_key' => '', 'base_url' => 'https://api.openai.com/v1']);
    }

    // ── SSE parsing tests ──────────────────────────────────────────────────

    public function testParseSseLineReturnsContentDelta(): void
    {
        $line = 'data: {"id":"x","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"content":"Hello"},"finish_reason":null}]}';
        $chunk = OpenAiProvider::parseSseLine($line);
        $this->assertInstanceOf(AiStreamChunk::class, $chunk);
        $this->assertSame('Hello', $chunk->delta);
        $this->assertNull($chunk->finishReason);
    }

    public function testParseSseLineWithFinishReason(): void
    {
        $line = 'data: {"id":"x","object":"chat.completion.chunk","choices":[{"index":0,"delta":{},"finish_reason":"stop"}]}';
        $chunk = OpenAiProvider::parseSseLine($line);
        $this->assertInstanceOf(AiStreamChunk::class, $chunk);
        $this->assertSame('', $chunk->delta);
        $this->assertSame('stop', $chunk->finishReason);
    }

    public function testParseSseLineWithUsage(): void
    {
        $line = 'data: {"id":"x","object":"chat.completion.chunk","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":10,"completion_tokens":5,"total_tokens":15}}';
        $chunk = OpenAiProvider::parseSseLine($line);
        $this->assertInstanceOf(AiStreamChunk::class, $chunk);
        $this->assertSame(10, $chunk->inputTokens);
        $this->assertSame(5, $chunk->outputTokens);
    }

    public function testParseSseLineWithModel(): void
    {
        $line = 'data: {"id":"x","object":"chat.completion.chunk","model":"gpt-4o","choices":[{"index":0,"delta":{"content":"Hi"},"finish_reason":null}]}';
        $chunk = OpenAiProvider::parseSseLine($line);
        $this->assertSame('gpt-4o', $chunk->model);
    }

    public function testParseSseLineReturnsNullForDone(): void
    {
        $this->assertNull(OpenAiProvider::parseSseLine('data: [DONE]'));
    }

    public function testParseSseLineReturnsNullForEmpty(): void
    {
        $this->assertNull(OpenAiProvider::parseSseLine(''));
        $this->assertNull(OpenAiProvider::parseSseLine(" \t "));
    }

    public function testParseSseLineReturnsNullForNonDataLine(): void
    {
        $this->assertNull(OpenAiProvider::parseSseLine('event: foo'));
        $this->assertNull(OpenAiProvider::parseSseLine(':comment'));
    }

    public function testParseSseLineReturnsNullForMalformedJson(): void
    {
        $this->assertNull(OpenAiProvider::parseSseLine('data: {broken json'));
    }
}
