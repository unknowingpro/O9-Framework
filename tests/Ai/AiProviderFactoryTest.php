<?php
declare(strict_types=1);

namespace Tests\Ai;

use App\Ai\AiProvider;
use App\Ai\AiProviderFactory;
use App\Ai\AiResponse;
use App\Ai\OpenAiProvider;
use PHPUnit\Framework\TestCase;

final class AiProviderFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        AiProviderFactory::reset();
        // Set env vars before config('ai.*') is called so the config file
        // picks them up. Tests run alphabetically so the config cache is
        // fresh at this point (no earlier test loaded it).
        putenv('OPENAI_API_KEY=sk-test');
        putenv('OPENAI_BASE_URL=https://api.openai.com/v1');
    }

    protected function tearDown(): void
    {
        putenv('OPENAI_API_KEY');
        putenv('OPENAI_BASE_URL');
    }

    public function testMakeReturnsOpenAiByDefault(): void
    {
        $provider = AiProviderFactory::make();
        $this->assertInstanceOf(OpenAiProvider::class, $provider);
        $this->assertSame('openai', $provider->name());
    }

    public function testMakeReturnsNamedProvider(): void
    {
        $provider = AiProviderFactory::make('openai');
        $this->assertInstanceOf(OpenAiProvider::class, $provider);
    }

    public function testExtendedProviderIsReturned(): void
    {
        $fake = new FakeAiProvider();
        AiProviderFactory::extend('fake', fn (): AiProvider => $fake);

        $provider = AiProviderFactory::make('fake');
        $this->assertSame($fake, $provider);
        $response = $provider->chat('x', []);
        $this->assertSame('fake response', $response->content);
    }

    public function testExtendDoesNotAffectDefault(): void
    {
        AiProviderFactory::extend('fake', fn () => new FakeAiProvider());
        $default = AiProviderFactory::make();
        $this->assertInstanceOf(OpenAiProvider::class, $default);
    }

    public function testUnknownProviderThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unknown AI provider: nope');
        AiProviderFactory::make('nope');
    }

    public function testResetClearsCustomProviders(): void
    {
        AiProviderFactory::extend('fake', fn () => new FakeAiProvider());
        AiProviderFactory::reset();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unknown AI provider: fake');
        AiProviderFactory::make('fake');
    }

    public function testActiveReturnsDefault(): void
    {
        $this->assertSame('openai', AiProviderFactory::active());
    }
}

final class FakeAiProvider implements AiProvider
{
    public function chat(string $model, array $messages, array $options = []): AiResponse
    {
        return AiResponse::with('fake response', 'fake-model');
    }

    public function stream(string $model, array $messages, array $options = []): \Generator
    {
        yield new \App\Ai\AiStreamChunk(delta: 'fake');
        yield new \App\Ai\AiStreamChunk(delta: ' stream', finishReason: 'stop');
    }

    public function name(): string
    {
        return 'fake';
    }
}
