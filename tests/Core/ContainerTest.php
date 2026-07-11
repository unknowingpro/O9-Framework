<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Container;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    protected function setUp(): void
    {
        Container::reset();
    }

    protected function tearDown(): void
    {
        Container::reset();
    }

    public function testBindReturnsFreshInstancePerMake(): void
    {
        Container::bind('thing', static fn (): \stdClass => new \stdClass());
        $this->assertNotSame(Container::make('thing'), Container::make('thing'));
    }

    public function testSingletonIsMemoised(): void
    {
        Container::singleton('thing', static fn (): \stdClass => new \stdClass());
        $this->assertSame(Container::make('thing'), Container::make('thing'));
    }

    public function testUnboundClassNameIsInstantiated(): void
    {
        $made = Container::make(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $made);
    }

    public function testRebindingSingletonDropsCachedInstance(): void
    {
        Container::singleton('thing', static fn (): \stdClass => new \stdClass());
        $first = Container::make('thing');
        Container::singleton('thing', static fn (): \stdClass => new \stdClass());
        $this->assertNotSame($first, Container::make('thing'));
    }

    public function testBindAfterSingletonMakesTransient(): void
    {
        Container::singleton('thing', static fn (): \stdClass => new \stdClass());
        Container::make('thing');
        Container::bind('thing', static fn (): \stdClass => new \stdClass());
        $this->assertNotSame(Container::make('thing'), Container::make('thing'));
    }

    public function testResetForgetsEverything(): void
    {
        Container::singleton('thing', static fn (): \stdClass => new \stdClass());
        $first = Container::make('thing');
        Container::reset();
        Container::singleton('thing', static fn (): \stdClass => new \stdClass());
        $this->assertNotSame($first, Container::make('thing'));
    }
}
