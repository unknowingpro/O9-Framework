<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Core\Job;
use App\Mail\MailgunTransport;
use App\Mail\Message;
use App\Mail\PhpMailTransport;
use App\Mail\SmtpTransport;
use App\Mail\Transport;

/**
 * Deliver an outbound email out of the request path. Enqueued at call sites
 * instead of sending inline so a slow MTA or transient failure neither
 * blocks the response nor silently drops the message — the queue retries
 * with backoff (see Core\Queue).
 *
 * Transport is resolved from config('mail.*) (env-driven). Apps that want
 * DB-backed admin-configurable mail settings can swap the resolution logic
 * here for one backed by their own settings service — this job is the
 * framework's starter, not a fixed contract.
 */
final class SendEmailJob implements Job
{
    public function handle(array $payload): void
    {
        $to      = self::stripHeaderInjection((string) ($payload['to'] ?? ''));
        $subject = self::stripHeaderInjection((string) ($payload['subject'] ?? ''));
        $body    = (string) ($payload['body'] ?? '');

        // Malformed recipient is a permanent failure, not a transient one —
        // retrying won't fix a bad address, so just drop it.
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $from = self::stripHeaderInjection((string) config('mail.from_address', ''));
        if ($from === '') {
            $host = parse_url((string) config('app.url', ''), PHP_URL_HOST);
            $from = 'no-reply@' . ($host !== null && $host !== '' ? $host : 'localhost');
        }
        $fromName = self::stripHeaderInjection((string) config('mail.from_name', ''));

        $driver = strtolower((string) config('mail.driver', 'log'));
        if ($driver === 'log') {
            self::logOnly($to, $subject, $body);
            return;
        }

        // Transports throw on failure — let it propagate so Queue::failed()
        // applies backoff/burial instead of silently reporting success.
        $this->transportFor($driver)->send(new Message($to, $subject, $body, $from, $fromName));
    }

    private function transportFor(string $driver): Transport
    {
        return match ($driver) {
            'smtp' => new SmtpTransport(
                (string) config('mail.smtp.host', ''),
                (int) config('mail.smtp.port', 587),
                (string) config('mail.smtp.encryption', 'tls'),
                (string) config('mail.smtp.username', ''),
                (string) config('mail.smtp.password', ''),
            ),
            'mailgun' => new MailgunTransport(
                (string) config('mail.mailgun.domain', ''),
                (string) config('mail.mailgun.secret', ''),
                (string) config('mail.mailgun.endpoint', 'api.mailgun.net'),
            ),
            default => new PhpMailTransport(),
        };
    }

    /**
     * Removes CR, LF, and NUL — the bytes that can split a header line and
     * inject a fresh one. Anything past the first CR/LF is dropped so
     * `victim@example.com\nBcc: attacker@evil.com` becomes the plain address.
     */
    private static function stripHeaderInjection(string $value): string
    {
        $cut = preg_split('/[\r\n\0]/', $value, 2)[0] ?? '';
        return trim($cut);
    }

    private static function logOnly(string $to, string $subject, string $body): void
    {
        $dir = base_path('storage/logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] TO=%s SUBJECT=%s\n%s\n%s\n",
            gmdate('Y-m-d H:i:s'),
            $to,
            $subject,
            $body,
            str_repeat('-', 60)
        );
        @file_put_contents($dir . '/mail-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
