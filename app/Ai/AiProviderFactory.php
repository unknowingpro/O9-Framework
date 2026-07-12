<?php
declare(strict_types=1);

namespace App\Ai;

use RuntimeException;

/**
 * Builds the active AiProvider adapter (parallel to PaymentGatewayFactory,
 * IdentityProviderFactory).
 *
 * Only 'openai' ships in core — custom providers (Anthropic native, Ollama
 * without the proxy layer, a custom fine-tune endpoint, etc.) register
 * themselves via extend(), the same driver/factory pattern used throughout
 * the framework.
 *
 * Usage:
 *   $ai = AiProviderFactory::make();
 *   $response = $ai->chat('gpt-4o', [['role' => 'user', 'content' => 'Hi']]);
 */
final class AiProviderFactory
{
    /** @var array<string, callable(): AiProvider> */
    private static array $custom = [];

    public static function make(?string $name = null): AiProvider
    {
        $name = $name ?? self::active();
        if (isset(self::$custom[$name])) {
            return (self::$custom[$name])();
        }
        return match ($name) {
            'openai' => new OpenAiProvider((array) config('ai.providers.openai', [])),
            default  => throw new RuntimeException('unknown AI provider: ' . $name),
        };
    }

    /** Register an additional provider by name. @param callable(): AiProvider $maker */
    public static function extend(string $name, callable $maker): void
    {
        self::$custom[$name] = $maker;
    }

    /** The configured active provider — config('ai.provider'), default 'openai'. */
    public static function active(): string
    {
        $name = (string) config('ai.provider', 'openai');
        return $name !== '' ? $name : 'openai';
    }

    /** @internal test reset */
    public static function reset(): void
    {
        self::$custom = [];
    }
}
