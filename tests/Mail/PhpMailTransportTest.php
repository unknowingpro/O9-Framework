<?php
declare(strict_types=1);

namespace Tests\Mail;

use App\Mail\Message;
use App\Mail\PhpMailTransport;
use PHPUnit\Framework\TestCase;

final class PhpMailTransportTest extends TestCase
{
    public function testThrowsWhenNoMtaIsAvailable(): void
    {
        // No local MTA in the CI/test environment — mail() returns false, so
        // the transport must throw rather than report a phantom success.
        $this->expectException(\RuntimeException::class);
        (new PhpMailTransport())->send(
            new Message('to@example.com', 'Subj', 'Body', 'from@example.com')
        );
    }
}
