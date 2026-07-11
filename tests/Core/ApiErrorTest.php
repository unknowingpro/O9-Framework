<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\ApiError;
use App\Core\HttpException;
use PHPUnit\Framework\TestCase;

final class ApiErrorTest extends TestCase
{
    public function testEveryCodeHasStatusAndMessage(): void
    {
        foreach (ApiError::codes() as $code) {
            $this->assertGreaterThanOrEqual(400, ApiError::status($code));
            $this->assertNotSame('', ApiError::defaultMessage($code));
        }
    }

    public function testStatusMapping(): void
    {
        $this->assertSame(422, ApiError::status(ApiError::VALIDATION));
        $this->assertSame(401, ApiError::status(ApiError::UNAUTHORIZED));
        $this->assertSame(404, ApiError::status(ApiError::NOT_FOUND));
        $this->assertSame(429, ApiError::status(ApiError::RATE_LIMITED));
        $this->assertSame(500, ApiError::status(ApiError::SERVER_ERROR));
        $this->assertSame(400, ApiError::status('made_up_code'));
    }

    public function testHttpExceptionFactoriesCarryCanonicalStatus(): void
    {
        $e = HttpException::notFound('Plan not found');
        $this->assertSame(404, $e->status);
        $this->assertSame(ApiError::NOT_FOUND, $e->errorCode);
        $this->assertSame('Plan not found', $e->userMessage());
        $this->assertSame('Plan not found', $e->getMessage());
    }

    public function testHttpExceptionDefaultsToCatalogMessage(): void
    {
        $e = HttpException::forbidden();
        $this->assertSame(403, $e->status);
        $this->assertSame(ApiError::defaultMessage(ApiError::FORBIDDEN), $e->userMessage());
    }

    public function testUserMessageNeverLeaksLogMessage(): void
    {
        $e = new HttpException(500, ApiError::SERVER_ERROR, 'PDOException: SQLSTATE[42S02] secret table');
        $this->assertSame('PDOException: SQLSTATE[42S02] secret table', $e->getMessage());
        $this->assertSame(ApiError::defaultMessage(ApiError::SERVER_ERROR), $e->userMessage());
    }

    public function testValidationCarriesFieldErrors(): void
    {
        $e = HttpException::validation(['email' => ['Email is invalid.']]);
        $this->assertSame(422, $e->status);
        $this->assertSame(['email' => ['Email is invalid.']], $e->details);
    }
}
