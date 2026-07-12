<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * A single chunk from a streaming LLM response.
 *
 * The first chunk may carry $model and $inputTokens (the implementation copies
 * them from the initial SSE event). The final chunk carries $finishReason and
 * $outputTokens. Mid-stream chunks carry only $delta.
 */
final class AiStreamChunk
{
    /**
     * @param string $delta  Content fragment for this chunk.
     * @param ?string $finishReason Set only on the terminal chunk.
     * @param ?string $model       Set only on the first chunk.
     * @param ?int $inputTokens    Set only on the first chunk.
     * @param ?int $outputTokens   Set only on the final chunk.
     */
    public function __construct(
        public readonly string  $delta = '',
        public readonly ?string $finishReason = null,
        public readonly ?string $model = null,
        public readonly ?int    $inputTokens = null,
        public readonly ?int    $outputTokens = null,
    ) {}
}
