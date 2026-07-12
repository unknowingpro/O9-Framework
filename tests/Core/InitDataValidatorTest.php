<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\InitDataValidator;
use PHPUnit\Framework\TestCase;

final class InitDataValidatorTest extends TestCase
{
    private const BOT_TOKEN = '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11';

    private function sign(array $params, string $botToken = self::BOT_TOKEN): string
    {
        $pairs = $params;
        ksort($pairs);
        $dataCheckStr = implode("\n", array_map(fn ($k, $v) => "{$k}={$v}", array_keys($pairs), $pairs));
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $hash = hash_hmac('sha256', $dataCheckStr, $secretKey);
        return http_build_query($params + ['hash' => $hash]);
    }

    public function testValidatesACorrectlySignedPayload(): void
    {
        $initData = $this->sign(['auth_date' => (string) time(), 'query_id' => 'AAH123']);
        $this->assertTrue(InitDataValidator::validate($initData, self::BOT_TOKEN));
    }

    public function testRejectsATamperedField(): void
    {
        $params = ['auth_date' => (string) time(), 'query_id' => 'AAH123'];
        $signed = $this->sign($params);
        $tampered = str_replace('AAH123', 'EVIL999', $signed);
        $this->assertFalse(InitDataValidator::validate($tampered, self::BOT_TOKEN));
    }

    public function testRejectsWrongBotToken(): void
    {
        $initData = $this->sign(['auth_date' => (string) time()]);
        $this->assertFalse(InitDataValidator::validate($initData, 'different-token'));
    }

    public function testRejectsMissingHash(): void
    {
        $this->assertFalse(InitDataValidator::validate('auth_date=' . time(), self::BOT_TOKEN));
    }

    public function testRejectsEmptyInputs(): void
    {
        $this->assertFalse(InitDataValidator::validate('', self::BOT_TOKEN));
        $this->assertFalse(InitDataValidator::validate('auth_date=1&hash=x', ''));
    }

    public function testRejectsStalePayloadPastMaxAge(): void
    {
        $initData = $this->sign(['auth_date' => (string) (time() - 7200)]);
        $this->assertFalse(InitDataValidator::validate($initData, self::BOT_TOKEN, 3600));
    }

    public function testMaxAgeZeroDisablesTheFreshnessCheck(): void
    {
        $initData = $this->sign(['auth_date' => (string) (time() - 999_999)]);
        $this->assertTrue(InitDataValidator::validate($initData, self::BOT_TOKEN, 0));
    }

    public function testRejectsMissingAuthDateWhenFreshnessCheckIsEnabled(): void
    {
        $initData = $this->sign(['query_id' => 'x']); // no auth_date at all
        $this->assertFalse(InitDataValidator::validate($initData, self::BOT_TOKEN));
    }

    public function testParseReturnsFieldsWithoutVerifying(): void
    {
        $parsed = InitDataValidator::parse('auth_date=123&query_id=abc&hash=whatever');
        $this->assertSame('123', $parsed['auth_date']);
        $this->assertSame('abc', $parsed['query_id']);
        $this->assertSame('whatever', $parsed['hash']);
    }

    public function testParseOfEmptyStringReturnsEmptyArray(): void
    {
        $this->assertSame([], InitDataValidator::parse(''));
    }
}
