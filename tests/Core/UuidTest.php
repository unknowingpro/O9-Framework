<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testV4MatchesTheRfc4122Shape(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            Uuid::v4()
        );
    }

    public function testVersionAndVariantBitsAreSet(): void
    {
        $uuid = Uuid::v4();
        $this->assertSame('4', $uuid[14], 'version nibble must be 4');
        $this->assertContains($uuid[19], ['8', '9', 'a', 'b'], 'variant must be 10xx');
    }

    public function testIdsAreUnique(): void
    {
        $ids = [];
        for ($i = 0; $i < 500; $i++) {
            $ids[] = Uuid::v4();
        }
        $this->assertCount(500, array_unique($ids));
    }

    public function testIsValid(): void
    {
        $this->assertTrue(Uuid::isValid(Uuid::v4()));
        $this->assertTrue(Uuid::isValid('123E4567-E89B-12D3-A456-426614174000'));
        $this->assertFalse(Uuid::isValid('not-a-uuid'));
        $this->assertFalse(Uuid::isValid(''));
        $this->assertFalse(Uuid::isValid('123e4567e89b12d3a456426614174000'));
    }
}
