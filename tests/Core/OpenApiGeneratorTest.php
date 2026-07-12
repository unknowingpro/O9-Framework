<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\OpenApiGenerator;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class OpenApiGeneratorTest extends TestCase
{
    public function testGeneratesOnlyApiPaths(): void
    {
        $router = new Router();
        $router->get('/api/v1/health', fn () => null);
        $router->get('/web/page', fn () => null);

        $doc = (new OpenApiGenerator())->generate($router);
        $this->assertArrayHasKey('/api/v1/health', $doc['paths']);
        $this->assertArrayNotHasKey('/web/page', $doc['paths']);
        $this->assertSame('3.0.3', $doc['openapi']);
    }

    public function testPathParametersAreExtracted(): void
    {
        $router = new Router();
        $router->get('/api/v1/users/{id}/posts/{postId}', fn () => null);
        $doc = (new OpenApiGenerator())->generate($router);
        $params = $doc['paths']['/api/v1/users/{id}/posts/{postId}']['get']['parameters'];
        $names = array_column($params, 'name');
        $this->assertSame(['id', 'postId'], $names);
        $this->assertTrue($params[0]['required']);
    }

    public function testAuthDetectionAddsBearerSecurity(): void
    {
        $router = new Router();
        $router->get('/api/v1/me', fn () => null, ['Auth']);
        $router->get('/api/v1/public', fn () => null);

        $doc = (new OpenApiGenerator())->generate($router);
        $this->assertSame([['bearerAuth' => []]], $doc['paths']['/api/v1/me']['get']['security']);
        $this->assertArrayNotHasKey('security', $doc['paths']['/api/v1/public']['get']);
    }

    public function testAuthShortNameWithArgIsDetected(): void
    {
        $router = new Router();
        $router->get('/api/v1/admin', fn () => null, ['Auth:admin']);
        $doc = (new OpenApiGenerator())->generate($router);
        $this->assertArrayHasKey('security', $doc['paths']['/api/v1/admin']['get']);
    }

    public function testTagIsTheResourceSegment(): void
    {
        $router = new Router();
        $router->get('/api/v1/widgets', fn () => null);
        $doc = (new OpenApiGenerator())->generate($router);
        $this->assertSame(['widgets'], $doc['paths']['/api/v1/widgets']['get']['tags']);
    }

    public function testOperationIdIsDerivedFromMethodAndPath(): void
    {
        $router = new Router();
        $router->post('/api/v1/widgets/{id}', fn () => null);
        $doc = (new OpenApiGenerator())->generate($router);
        $this->assertSame('post_widgets_id', $doc['paths']['/api/v1/widgets/{id}']['post']['operationId']);
    }

    public function testMultipleMethodsOnTheSamePathAreBothDocumented(): void
    {
        $router = new Router();
        $router->get('/api/v1/widgets', fn () => null);
        $router->post('/api/v1/widgets', fn () => null);
        $doc = (new OpenApiGenerator())->generate($router);
        $this->assertArrayHasKey('get', $doc['paths']['/api/v1/widgets']);
        $this->assertArrayHasKey('post', $doc['paths']['/api/v1/widgets']);
    }

    public function testComponentsIncludeTheEnvelopeSchema(): void
    {
        $router = new Router();
        $doc = (new OpenApiGenerator())->generate($router);
        $this->assertArrayHasKey('Envelope', $doc['components']['schemas']);
        $this->assertArrayHasKey('bearerAuth', $doc['components']['securitySchemes']);
    }
}
