<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * A completed LLM chat response — content, token usage, finish reason, model ID.
 *
 * All properties are public readonly. Create with the named constructor or
 * positional constructor, then read — never mutate.
 *
 * Named constructor (preferred for clarity):
 *   AiResponse::with(string $content, ...)
 *
 * Or positional:
 *   new AiResponse($content, $model, ...)
 */
final class AiResponse
{
    /**
     * @param string $content The full response text.
     * @param string $model   The model that generated this response.
     * @param ?int $inputTokens  Tokens consumed by the prompt (null when unknown).
     * @param ?int $outputTokens Tokens consumed by the response (null when unknown).
     * @param ?string $finishReason 'stop', 'length', 'content_filter', 'tool_calls', or null.
     */
    public function __construct(
        public readonly string  $content,
        public readonly string  $model,
        public readonly ?int    $inputTokens = null,
        public readonly ?int    $outputTokens = null,
        public readonly ?string $finishReason = null,
    ) {}

    /** Convenience named constructor — makes the intent at call sites clearer. */
    public static function with(
        string  $content,
        string  $model,
        ?int    $inputTokens = null,
        ?int    $outputTokens = null,
        ?string $finishReason = null,
    ): self {
        return new self($content, $model, $inputTokens, $outputTokens, $finishReason);
    }
}
