<?php
declare(strict_types=1);

namespace App\Ai;

/**
 * AiProvider — the port for an LLM provider (OpenAI, Claude, Ollama, etc.).
 *
 * Implementations talk to a third-party API using the framework's HttpClient
 * (which includes SSRF protection) and return normalized value objects.
 *
 * @see OpenAiProvider for the canonical OpenAI-compatible implementation.
 */
interface AiProvider
{
    /**
     * Send a chat completion request and return the full response.
     *
     * @param list<array{role: string, content: string}> $messages
     *        OpenAI-style: [['role' => 'system'|'user'|'assistant', 'content' => '...']]
     * @param array<string, mixed> $options
     *        temperature, max_tokens, top_p, stop, and any provider-specific extras.
     */
    public function chat(string $model, array $messages, array $options = []): AiResponse;

    /**
     * Stream a chat completion via SSE. Returns a Generator that yields
     * AiStreamChunk objects as each delta arrives.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @return \Generator<AiStreamChunk>
     */
    public function stream(string $model, array $messages, array $options = []): \Generator;

    /** Stable provider key, e.g. 'openai', 'anthropic', 'ollama'. */
    public function name(): string;
}
