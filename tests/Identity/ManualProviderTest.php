<?php
declare(strict_types=1);

namespace Tests\Identity;

use App\Identity\Provider\ManualProvider;
use App\Identity\UnsupportedOperation;
use PHPUnit\Framework\TestCase;

final class ManualProviderTest extends TestCase
{
    public function testNameAndCapabilities(): void
    {
        $p = new ManualProvider();
        $this->assertSame('manual', $p->name());
        $this->assertSame(['manual'], $p->capabilities());
    }

    public function testCreateSessionReturnsAManualModeSession(): void
    {
        $session = (new ManualProvider())->createSession(1, 'https://example.com/return');
        $this->assertSame('manual', $session->provider);
        $this->assertSame('', $session->ref);
        $this->assertSame('manual', $session->mode);
        $this->assertNotNull($session->redirectUrl);
    }

    public function testWebhookAndStatusAreUnsupported(): void
    {
        $p = new ManualProvider();
        try {
            $p->verifyWebhook('{}', []);
            $this->fail('expected UnsupportedOperation');
        } catch (UnsupportedOperation) {
            $this->addToAssertionCount(1);
        }
        try {
            $p->fetchStatus('ref');
            $this->fail('expected UnsupportedOperation');
        } catch (UnsupportedOperation) {
            $this->addToAssertionCount(1);
        }
    }
}
