<?php
declare(strict_types=1);

namespace App\Mail;

use RuntimeException;

/**
 * Tiny, dependency-free SMTP transport — enough to deliver transactional
 * plain-text mail (password reset, email-change confirmation, notifications)
 * without pulling in PHPMailer/Symfony Mailer.
 *
 * Supports STARTTLS (e.g. port 587), implicit SSL (port 465) and plain (25),
 * with optional AUTH LOGIN. One connection per message — fine at this volume
 * (it runs in the queue worker, off the request path).
 */
final class SmtpTransport implements Transport
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption = 'tls', // 'tls' | 'ssl' | 'none'
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly int $timeout = 15,
    ) {
    }

    public function send(Message $message): void
    {
        $transport = $this->encryption === 'ssl' ? 'ssl://' : '';
        $fp = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno, $errstr, (float) $this->timeout,
            STREAM_CLIENT_CONNECT
        );
        if ($fp === false) {
            throw new RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($fp, $this->timeout);
        try {
            $this->expect($fp, 220);
            $ehlo = self::ehloName($message->from);
            $this->cmd($fp, 'EHLO ' . $ehlo, 250);

            if ($this->encryption === 'tls') {
                $this->cmd($fp, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS negotiation failed');
                }
                $this->cmd($fp, 'EHLO ' . $ehlo, 250); // must re-EHLO after the upgrade
            }

            if ($this->username !== '') {
                $this->cmd($fp, 'AUTH LOGIN', 334);
                $this->cmd($fp, base64_encode($this->username), 334);
                $this->cmd($fp, base64_encode($this->password), 235);
            }

            $this->cmd($fp, 'MAIL FROM:<' . $message->from . '>', 250);
            $this->cmd($fp, 'RCPT TO:<' . $message->to . '>', 250);
            $this->cmd($fp, 'DATA', 354);
            fwrite($fp, self::buildMessage(
                $message->from, $message->fromName, $message->to, $message->subject, $message->body
            ) . "\r\n.\r\n");
            $this->expect($fp, 250);
            $this->cmd($fp, 'QUIT', 221);
        } finally {
            fclose($fp);
        }
    }

    /**
     * Build the RFC-5322 message (headers + dot-stuffed CRLF body). Public +
     * static so it can be unit-tested without a socket.
     */
    public static function buildMessage(string $from, string $fromName, string $to, string $subject, string $body): string
    {
        $fromHeader = $fromName !== '' ? '"' . self::stripCrlf($fromName) . '" <' . $from . '>' : $from;
        $headers = [
            'From: ' . $fromHeader,
            'To: ' . $to,
            'Subject: ' . self::encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        ];
        // Normalise to CRLF, then dot-stuff lines beginning with '.' (RFC 5321 §4.5.2).
        $body = str_replace("\r\n", "\n", $body);
        $body = (string) preg_replace('/^\./m', '..', $body);
        $body = str_replace("\n", "\r\n", $body);
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /** MIME encoded-word for a header value when it isn't plain ASCII. */
    private static function encodeHeader(string $value): string
    {
        $value = self::stripCrlf($value);
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    private static function stripCrlf(string $v): string
    {
        return trim((string) (preg_split('/[\r\n\0]/', $v, 2)[0] ?? ''));
    }

    /** EHLO identity from the sender domain (fallback to localhost). */
    private static function ehloName(string $from): string
    {
        $at = strrchr($from, '@');
        $domain = $at !== false ? substr($at, 1) : '';
        return $domain !== '' ? $domain : 'localhost';
    }

    /** $fp is an open SMTP stream resource. */
    private function cmd(mixed $fp, string $line, int $expect): void
    {
        fwrite($fp, $line . "\r\n");
        $this->expect($fp, $expect);
    }

    /** $fp is an open SMTP stream resource. */
    private function expect(mixed $fp, int $code): void
    {
        $reply = '';
        while (($line = fgets($fp, 515)) !== false) {
            $reply .= $line;
            // A space (not '-') in the 4th column marks the final line.
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        if ((int) substr($reply, 0, 3) !== $code) {
            throw new RuntimeException("SMTP expected {$code}, got: " . trim($reply));
        }
    }
}
