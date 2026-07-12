<?php
declare(strict_types=1);

namespace App\Mail;

use RuntimeException;

/**
 * Mailgun HTTP API transport — POSTs to the v3 messages endpoint, Basic-authed
 * with the 'api' user + secret key. Hand-rolled cURL (no shared HttpClient
 * dependency — this transport predates it) with an injectable $poster so it
 * can be unit-tested without a network call.
 *
 * @see https://documentation.mailgun.com/en/latest/api-sending.html
 */
final class MailgunTransport implements Transport
{
    /** @var (callable(string, array<string,string>, list<string>): array{status: int, body: string})|null */
    private $poster;

    /** @param (callable(string, array<string,string>, list<string>): array{status: int, body: string})|null $poster */
    public function __construct(
        private readonly string $domain,
        private readonly string $secret,
        private readonly string $endpoint = 'api.mailgun.net',
        ?callable $poster = null,
    ) {
        $this->poster = $poster;
    }

    public function send(Message $message): void
    {
        $from = $message->fromName !== ''
            ? $message->fromName . ' <' . $message->from . '>'
            : $message->from;

        $url = 'https://' . $this->endpoint . '/v3/' . $this->domain . '/messages';
        $fields = [
            'from'    => $from,
            'to'      => $message->to,
            'subject' => $message->subject,
            'text'    => $message->body,
        ];
        $headers = ['Authorization: Basic ' . base64_encode('api:' . $this->secret)];

        $result = $this->poster !== null
            ? ($this->poster)($url, $fields, $headers)
            : self::curlPostForm($url, $fields, $headers);

        if ($result['status'] < 200 || $result['status'] >= 300) {
            // Mailgun returns {"message": "..."} on error; fall back to the raw body.
            $decoded = json_decode($result['body'], true);
            $detail = is_array($decoded) ? (string) ($decoded['message'] ?? $result['body']) : $result['body'];
            throw new RuntimeException('Mailgun HTTP ' . $result['status'] . ': ' . substr($detail, 0, 200));
        }
    }

    /**
     * @param array<string, string> $fields
     * @param list<string> $headers
     * @return array{status: int, body: string}
     */
    private static function curlPostForm(string $url, array $fields, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            throw new RuntimeException("Mailgun cURL error: $err");
        }
        return ['status' => $status, 'body' => (string) $body];
    }
}
