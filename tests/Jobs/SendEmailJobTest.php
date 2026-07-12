<?php
declare(strict_types=1);

namespace Tests\Jobs;

use App\Jobs\SendEmailJob;
use PHPUnit\Framework\TestCase;

final class SendEmailJobTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = base_path('storage/logs/mail-' . gmdate('Y-m-d') . '.log');
        @unlink($this->logFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
    }

    public function testLogDriverWritesAnAuditLineAndDoesNotThrow(): void
    {
        (new SendEmailJob())->handle([
            'to'      => 'user@example.com',
            'subject' => 'Welcome',
            'body'    => 'Hello there',
        ]);

        $this->assertFileExists($this->logFile);
        $contents = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('TO=user@example.com', $contents);
        $this->assertStringContainsString('SUBJECT=Welcome', $contents);
        $this->assertStringContainsString('Hello there', $contents);
    }

    public function testInvalidRecipientIsDroppedSilently(): void
    {
        (new SendEmailJob())->handle(['to' => 'not-an-email', 'subject' => 'x', 'body' => 'y']);
        $this->assertFileDoesNotExist($this->logFile);
    }

    public function testEmptyRecipientIsDroppedSilently(): void
    {
        (new SendEmailJob())->handle(['subject' => 'x', 'body' => 'y']);
        $this->assertFileDoesNotExist($this->logFile);
    }

    public function testHeaderInjectionIsStrippedFromToAndSubject(): void
    {
        (new SendEmailJob())->handle([
            'to'      => "user@example.com\nBcc: attacker@evil.com",
            'subject' => "Hi\r\nX-Injected: yes",
            'body'    => 'body',
        ]);
        $contents = (string) file_get_contents($this->logFile);
        $this->assertStringNotContainsString('Bcc:', $contents);
        $this->assertStringNotContainsString('X-Injected', $contents);
    }
}
