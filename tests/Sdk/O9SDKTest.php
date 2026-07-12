<?php
declare(strict_types=1);

namespace Tests\Sdk;

use PHPUnit\Framework\TestCase;

/**
 * sdk/O9SDK.php is a standalone file (no framework dependency, no
 * composer autoload entry — it's meant to be copied into another PHP
 * project), so it's require'd directly here rather than autoloaded.
 *
 * Regression: O9ApiException originally declared its error-code property as
 * `public readonly string $code`, which collides with \Exception's own
 * non-readonly $code property — PHP fatals the whole class declaration
 * ("Cannot redeclare non-readonly property Exception::$code as readonly")
 * the moment this file is loaded. There was no test at all covering this
 * file, so it shipped completely unusable — literally any require of it,
 * or any attempt to construct O9ApiException, crashed. Merely reaching the
 * assertions below (i.e. the file loading and the class constructing) is
 * itself the regression check.
 */
final class O9SDKTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/sdk/O9SDK.php';
    }

    public function testApiExceptionConstructsWithoutFatalingAndExposesItsFields(): void
    {
        $e = new \O9\Sdk\O9ApiException('unauthorized', 'Authentication required.', 401, ['hint' => 'missing token']);

        $this->assertSame('unauthorized', $e->errorCode);
        $this->assertSame('Authentication required.', $e->getMessage());
        $this->assertSame(401, $e->status);
        $this->assertSame(['hint' => 'missing token'], $e->details);
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testApiExceptionDetailsDefaultToNull(): void
    {
        $e = new \O9\Sdk\O9ApiException('bad_response', 'oops', 502);
        $this->assertNull($e->details);
    }

    public function testSdkConstructsAndAcceptsATokenWithoutMakingAnyRequest(): void
    {
        $sdk = new \O9\Sdk\O9SDK('https://example.test/api/v1', 'initial-token');
        $sdk->setToken('replaced-token');
        // Reaching this line without a fatal/thrown error is the assertion —
        // construction and setToken() must not perform any I/O.
        $this->addToAssertionCount(1);
    }
}
