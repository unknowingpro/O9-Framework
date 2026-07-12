<?php
declare(strict_types=1);

namespace Tests\Identity;

use App\Identity\IdentityProviderFactory;
use App\Identity\Provider\ManualProvider;
use PHPUnit\Framework\TestCase;

final class IdentityProviderFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        IdentityProviderFactory::reset();
    }

    public function testMakeDefaultsToManual(): void
    {
        $this->assertInstanceOf(ManualProvider::class, IdentityProviderFactory::make());
        $this->assertInstanceOf(ManualProvider::class, IdentityProviderFactory::make('manual'));
    }

    public function testActiveReadsConfigWithManualFallback(): void
    {
        $this->assertSame('manual', IdentityProviderFactory::active());
    }

    public function testThrowsForUnknownProvider(): void
    {
        $this->expectException(\RuntimeException::class);
        IdentityProviderFactory::make('nope');
    }

    public function testExtendRegistersACustomProvider(): void
    {
        $fake = new ManualProvider(); // stand-in instance; identity is what matters
        IdentityProviderFactory::extend('custom', fn () => $fake);
        $this->assertSame($fake, IdentityProviderFactory::make('custom'));
    }
}
