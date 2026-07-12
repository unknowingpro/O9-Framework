<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Seo;
use PHPUnit\Framework\TestCase;

final class SeoTest extends TestCase
{
    protected function setUp(): void
    {
        Seo::reset();
    }

    protected function tearDown(): void
    {
        Seo::reset();
    }

    public function testDefaultsAreNullUntilSet(): void
    {
        $this->assertNull(Seo::title());
        $this->assertNull(Seo::description());
        $this->assertNull(Seo::image());
        $this->assertNull(Seo::type());
        $this->assertNull(Seo::url());
        $this->assertNull(Seo::jsonLdJson());
    }

    public function testSetOnlyOverwritesProvidedFields(): void
    {
        Seo::set(title: 'Home', description: 'Welcome');
        Seo::set(title: 'Updated Home'); // description untouched
        $this->assertSame('Updated Home', Seo::title());
        $this->assertSame('Welcome', Seo::description());
    }

    public function testSetAllFields(): void
    {
        Seo::set('T', 'D', 'https://img', 'article', 'https://example.com/x');
        $this->assertSame('T', Seo::title());
        $this->assertSame('D', Seo::description());
        $this->assertSame('https://img', Seo::image());
        $this->assertSame('article', Seo::type());
        $this->assertSame('https://example.com/x', Seo::url());
    }

    public function testJsonLdSingleObjectEmitsAContextNode(): void
    {
        Seo::jsonLd(['@type' => 'Person', 'name' => 'Sara']);
        $decoded = json_decode((string) Seo::jsonLdJson(), true);
        $this->assertSame('https://schema.org', $decoded['@context']);
        $this->assertSame('Person', $decoded['@type']);
        $this->assertSame('Sara', $decoded['name']);
        $this->assertArrayNotHasKey('@graph', $decoded);
    }

    public function testJsonLdMultipleObjectsEmitAGraph(): void
    {
        Seo::jsonLd(['@type' => 'Person', 'name' => 'A']);
        Seo::jsonLd(['@type' => 'Organization', 'name' => 'B']);
        $decoded = json_decode((string) Seo::jsonLdJson(), true);
        $this->assertSame('https://schema.org', $decoded['@context']);
        $this->assertCount(2, $decoded['@graph']);
        $this->assertSame('A', $decoded['@graph'][0]['name']);
    }

    public function testJsonLdIgnoresEmptyArrays(): void
    {
        Seo::jsonLd([]);
        $this->assertNull(Seo::jsonLdJson());
    }

    public function testResetClearsEverything(): void
    {
        Seo::set('T', 'D', 'I', 'type', 'U');
        Seo::jsonLd(['@type' => 'Thing']);
        Seo::reset();
        $this->assertNull(Seo::title());
        $this->assertNull(Seo::jsonLdJson());
    }
}
