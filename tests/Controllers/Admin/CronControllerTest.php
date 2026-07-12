<?php
declare(strict_types=1);

namespace Tests\Controllers\Admin;

use App\Controllers\Admin\CronController;
use App\Core\HttpException;
use App\Core\Request;
use PHPUnit\Framework\TestCase;

final class CronControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = [];
        unset($_SERVER['HTTP_X_CRON_SECRET']);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        unset($_SERVER['HTTP_X_CRON_SECRET']);
    }

    public function testRejectsWhenNoSecretIsConfigured(): void
    {
        // config('app.cron_secret') ships empty by default, so the endpoint
        // must never accept an unconfigured/empty secret as a match.
        $_GET['secret'] = '';
        $this->expectException(HttpException::class);
        try {
            (new CronController())->run(new Request());
        } catch (HttpException $e) {
            $this->assertSame(401, $e->status);
            throw $e;
        }
    }

    public function testRejectsAnArbitraryGivenSecretWhenNoneIsConfigured(): void
    {
        $_GET['secret'] = 'some-guess';
        $this->expectException(HttpException::class);
        try {
            (new CronController())->run(new Request());
        } catch (HttpException $e) {
            $this->assertSame(401, $e->status);
            throw $e;
        }
    }

    public function testReadsTheSecretFromTheHeaderWhenTheQueryIsAbsent(): void
    {
        $_SERVER['HTTP_X_CRON_SECRET'] = 'header-guess';
        $this->expectException(HttpException::class);
        (new CronController())->run(new Request());
    }
}
