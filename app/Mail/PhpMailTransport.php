<?php
declare(strict_types=1);

namespace App\Mail;

use RuntimeException;

/**
 * Default transport: hands the message to PHP mail(), which relies on a local
 * MTA / sendmail. Throws when mail() reports the message wasn't accepted so
 * the caller can log + (the queue can) retry, rather than reporting a false
 * success.
 */
final class PhpMailTransport implements Transport
{
    public function send(Message $message): void
    {
        $headers = 'From: ' . $message->fromHeader() . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";
        if (!@mail($message->to, $message->subject, $message->body, $headers)) {
            throw new RuntimeException('mail() did not accept the message (no local MTA?)');
        }
    }
}
