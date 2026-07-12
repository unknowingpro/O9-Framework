<?php
declare(strict_types=1);

namespace Tests\Mail;

use App\Mail\SmtpTransport;
use PHPUnit\Framework\TestCase;

final class SmtpTransportTest extends TestCase
{
    public function testBuildMessageProducesRfc5322HeadersAndCrlfBody(): void
    {
        $raw = SmtpTransport::buildMessage('from@example.com', 'Sara', 'to@example.com', 'Hi', "line1\nline2");

        $this->assertStringContainsString('From: "Sara" <from@example.com>' . "\r\n", $raw);
        $this->assertStringContainsString('To: to@example.com' . "\r\n", $raw);
        $this->assertStringContainsString('Subject: Hi' . "\r\n", $raw);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $raw);
        $this->assertStringContainsString("line1\r\nline2", $raw);
    }

    public function testBuildMessageWithoutFromNameOmitsQuoting(): void
    {
        $raw = SmtpTransport::buildMessage('from@example.com', '', 'to@example.com', 'Hi', 'body');
        $this->assertStringContainsString('From: from@example.com' . "\r\n", $raw);
    }

    public function testBuildMessageDotStuffsLeadingDots(): void
    {
        // RFC 5321 §4.5.2 — a line starting with '.' must be escaped to '..'
        // so the SMTP server doesn't treat it as the end-of-data marker.
        $raw = SmtpTransport::buildMessage('from@example.com', '', 'to@example.com', 'Hi', ".starts with a dot");
        $this->assertStringContainsString("\r\n\r\n..starts with a dot", $raw);
    }

    public function testBuildMessageMimeEncodesNonAsciiSubject(): void
    {
        $raw = SmtpTransport::buildMessage('from@example.com', '', 'to@example.com', 'Héllo', 'body');
        $this->assertStringContainsString('Subject: =?UTF-8?B?', $raw);
    }

    public function testBuildMessageStripsCrlfFromFromName(): void
    {
        $raw = SmtpTransport::buildMessage('from@example.com', "Evil\r\nBcc: x@evil.com", 'to@example.com', 'Hi', 'body');
        $this->assertStringNotContainsString('Bcc:', $raw);
    }

    public function testSendThrowsWhenTheHostIsUnreachable(): void
    {
        // 127.0.0.1:1 — nothing listens there; connect must fail fast (timeout param).
        $transport = new SmtpTransport('127.0.0.1', 1, 'none', '', '', 1);
        $this->expectException(\RuntimeException::class);
        $transport->send(new \App\Mail\Message('to@example.com', 'Hi', 'body', 'from@example.com'));
    }
}
