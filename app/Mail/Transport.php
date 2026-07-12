<?php
declare(strict_types=1);

namespace App\Mail;

/**
 * A single mail delivery transport (SMTP, Mailgun, PHP mail(), …).
 *
 * Implementations deliver one Message and THROW on any failure — connect,
 * auth, protocol, non-2xx response. Callers (e.g. SendEmailJob) let the
 * throwable propagate so the queue's retry/backoff/burial handles it,
 * rather than swallowing the error and reporting a phantom success.
 */
interface Transport
{
    public function send(Message $message): void;
}
