<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        Logger::reset();
        $this->logFile = base_path('storage/logs/app-' . date('Y-m-d') . '.log');
        @unlink($this->logFile);
    }

    protected function tearDown(): void
    {
        Logger::reset();
        @unlink($this->logFile);
    }

    private function lastLine(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($this->logFile)));
        $decoded = json_decode((string) end($lines), true);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    public function testInfoWritesALineWithoutPersisting(): void
    {
        $persisted = false;
        Logger::persistUsing(function () use (&$persisted): void { $persisted = true; });
        Logger::info('some.message', ['x' => 1]);
        $entry = $this->lastLine();
        $this->assertSame('INFO', $entry['level']);
        $this->assertSame('some.message', $entry['msg']);
        $this->assertSame(1, $entry['x']);
        $this->assertFalse($persisted);
    }

    public function testErrorWritesAndPersists(): void
    {
        $seen = null;
        Logger::persistUsing(function (string $channel, array $entry) use (&$seen): void {
            $seen = [$channel, $entry];
        });
        Logger::error('mail.send_failed', ['to' => 'x@example.com']);
        $entry = $this->lastLine();
        $this->assertSame('ERROR', $entry['level']);
        $this->assertNotNull($seen);
        $this->assertSame('mail', $seen[0]); // channel derived from dotted prefix
    }

    public function testWarningPersistsWithExplicitChannel(): void
    {
        $seen = null;
        Logger::persistUsing(function (string $channel, array $entry) use (&$seen): void {
            $seen = $channel;
        });
        Logger::warning('no-dots-here', [], 'custom');
        $this->assertSame('custom', $seen);
    }

    public function testEventAlwaysPersistsAsInfo(): void
    {
        $seen = null;
        Logger::persistUsing(function (string $channel, array $entry) use (&$seen): void {
            $seen = [$channel, $entry['level']];
        });
        Logger::event('security', 'admin.login', ['user_id' => 5]);
        $this->assertSame(['security', 'INFO'], $seen);
    }

    public function testChannelDerivedFromMessagePrefixFallsBackToApp(): void
    {
        $seen = null;
        Logger::persistUsing(function (string $channel) use (&$seen): void { $seen = $channel; });
        Logger::error('NoDotsAtAll');
        $this->assertSame('app', $seen);
    }

    public function testChannelPrefixMustLookLikeAnIdentifier(): void
    {
        $seen = null;
        Logger::persistUsing(function (string $channel) use (&$seen): void { $seen = $channel; });
        Logger::error('123bad.thing'); // prefix starts with a digit — invalid channel name
        $this->assertSame('app', $seen);
    }

    public function testExceptionLogsMessageFileAndTrace(): void
    {
        $seen = null;
        Logger::persistUsing(function (string $channel, array $entry) use (&$seen): void { $seen = $entry; });
        try {
            throw new \RuntimeException('boom');
        } catch (\RuntimeException $e) {
            Logger::exception($e);
        }
        $this->assertSame('boom', $seen['msg']);
        $this->assertSame(\RuntimeException::class, $seen['exception']);
        $this->assertIsArray($seen['trace']);
    }

    public function testQueryStringCredentialsAreRedacted(): void
    {
        $prevUri = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = '/api/x?api_key=SUPERSECRET&other=1';
        try {
            Logger::info('some.message');
            $entry = $this->lastLine();
            $this->assertStringContainsString('api_key=REDACTED', $entry['uri']);
            $this->assertStringNotContainsString('SUPERSECRET', $entry['uri']);
        } finally {
            if ($prevUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $prevUri;
            }
        }
    }

    public function testWithoutARegisteredPersisterErrorStillWritesToFile(): void
    {
        Logger::error('no.persister.registered');
        $entry = $this->lastLine();
        $this->assertSame('ERROR', $entry['level']);
    }
}
