<?php
declare(strict_types=1);

namespace Tests\Mail;

use App\Mail\MailgunTransport;
use App\Mail\Message;
use PHPUnit\Framework\TestCase;

final class MailgunTransportTest extends TestCase
{
    public function testSendPostsExpectedFieldsAndAuthHeader(): void
    {
        $captured = null;
        $poster = function (string $url, array $fields, array $headers) use (&$captured): array {
            $captured = [$url, $fields, $headers];
            return ['status' => 200, 'body' => '{"id":"abc"}'];
        };
        $transport = new MailgunTransport('mg.example.com', 'secret-key', 'api.mailgun.net', $poster);
        $transport->send(new Message('to@example.com', 'Hi', 'Body', 'from@example.com', 'Sara'));

        [$url, $fields, $headers] = $captured;
        $this->assertSame('https://api.mailgun.net/v3/mg.example.com/messages', $url);
        $this->assertSame('Sara <from@example.com>', $fields['from']);
        $this->assertSame('to@example.com', $fields['to']);
        $this->assertSame('Hi', $fields['subject']);
        $this->assertSame('Body', $fields['text']);
        $this->assertSame('Authorization: Basic ' . base64_encode('api:secret-key'), $headers[0]);
    }

    public function testSendWithoutFromNameOmitsAngleBrackets(): void
    {
        $captured = null;
        $poster = function (string $url, array $fields, array $headers) use (&$captured): array {
            $captured = $fields;
            return ['status' => 200, 'body' => ''];
        };
        (new MailgunTransport('mg.example.com', 'secret', 'api.mailgun.net', $poster))
            ->send(new Message('to@example.com', 'Hi', 'Body', 'from@example.com'));
        $this->assertSame('from@example.com', $captured['from']);
    }

    public function testThrowsWithMailgunErrorMessageOnFailure(): void
    {
        $poster = fn (string $url, array $fields, array $headers): array =>
            ['status' => 401, 'body' => '{"message":"Forbidden"}'];
        $transport = new MailgunTransport('mg.example.com', 'bad-key', 'api.mailgun.net', $poster);

        try {
            $transport->send(new Message('to@example.com', 'Hi', 'Body', 'from@example.com'));
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('401', $e->getMessage());
            $this->assertStringContainsString('Forbidden', $e->getMessage());
        }
    }

    public function testThrowsWithRawBodyWhenResponseIsNotJson(): void
    {
        $poster = fn (string $url, array $fields, array $headers): array =>
            ['status' => 500, 'body' => 'Internal Server Error'];
        $transport = new MailgunTransport('mg.example.com', 'key', 'api.mailgun.net', $poster);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Internal Server Error');
        $transport->send(new Message('to@example.com', 'Hi', 'Body', 'from@example.com'));
    }

    public function testSuccessRangeDoesNotThrow(): void
    {
        $poster = fn (string $url, array $fields, array $headers): array => ['status' => 202, 'body' => 'ok'];
        (new MailgunTransport('mg.example.com', 'key', 'api.mailgun.net', $poster))
            ->send(new Message('to@example.com', 'Hi', 'Body', 'from@example.com'));
        $this->addToAssertionCount(1);
    }
}
