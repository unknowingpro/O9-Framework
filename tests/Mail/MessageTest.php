<?php
declare(strict_types=1);

namespace Tests\Mail;

use App\Mail\Message;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function testFromHeaderWithDisplayName(): void
    {
        $m = new Message('to@example.com', 'Subj', 'Body', 'from@example.com', 'Sara');
        $this->assertSame('"Sara" <from@example.com>', $m->fromHeader());
    }

    public function testFromHeaderWithoutDisplayName(): void
    {
        $m = new Message('to@example.com', 'Subj', 'Body', 'from@example.com');
        $this->assertSame('from@example.com', $m->fromHeader());
    }

    public function testFieldsAreReadable(): void
    {
        $m = new Message('to@example.com', 'Subj', 'Body', 'from@example.com', 'Sara');
        $this->assertSame('to@example.com', $m->to);
        $this->assertSame('Subj', $m->subject);
        $this->assertSame('Body', $m->body);
        $this->assertSame('from@example.com', $m->from);
        $this->assertSame('Sara', $m->fromName);
    }
}
