<?php
declare(strict_types=1);

namespace Tests\Identity;

use App\Identity\Dto\VerificationEvent;
use App\Identity\Dto\VerificationSession;
use App\Identity\IdentityProviderFactory;
use App\Identity\IdentityVerificationProvider;
use App\Identity\IdentityVerificationService;
use PHPUnit\Framework\TestCase;

final class IdentityVerificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        IdentityVerificationService::reset();
        IdentityProviderFactory::reset();
    }

    protected function tearDown(): void
    {
        IdentityVerificationService::reset();
        IdentityProviderFactory::reset();
    }

    public function testActiveModeReflectsFactory(): void
    {
        $this->assertSame('manual', (new IdentityVerificationService())->activeMode());
    }

    public function testStartWithManualDoesNotInvokeSessionOpenedHook(): void
    {
        $called = false;
        IdentityVerificationService::onSessionOpenedUsing(function () use (&$called): void {
            $called = true;
        });
        $session = (new IdentityVerificationService())->start(1, 'https://x/return');
        $this->assertSame('manual', $session->mode);
        $this->assertFalse($called); // manual sessions have no ref — hook only fires for redirect+ref
    }

    public function testHandleWebhookAppliesTerminalDecisionViaHook(): void
    {
        IdentityProviderFactory::extend('fake', fn () => new class implements IdentityVerificationProvider {
            public function createSession(int $userId, string $returnUrl): VerificationSession
            {
                return new VerificationSession('fake', '', 'manual');
            }
            public function verifyWebhook(string $payload, array $headers): VerificationEvent
            {
                return new VerificationEvent('ref-1', VerificationEvent::VERIFIED, null, null);
            }
            public function fetchStatus(string $ref): VerificationEvent
            {
                return new VerificationEvent($ref, VerificationEvent::PENDING);
            }
            public function capabilities(): array { return ['webhook']; }
            public function name(): string { return 'fake'; }
        });

        $seen = null;
        IdentityVerificationService::onDecisionUsing(function (string $provider, string $ref, bool $verified, ?string $reason) use (&$seen): void {
            $seen = [$provider, $ref, $verified, $reason];
        });

        (new IdentityVerificationService())->handleWebhook('fake', '{}', []);
        $this->assertSame(['fake', 'ref-1', true, null], $seen);
    }

    public function testHandleWebhookSkipsPendingEvents(): void
    {
        IdentityProviderFactory::extend('fake-pending', fn () => new class implements IdentityVerificationProvider {
            public function createSession(int $userId, string $returnUrl): VerificationSession
            {
                return new VerificationSession('fake-pending', '', 'manual');
            }
            public function verifyWebhook(string $payload, array $headers): VerificationEvent
            {
                return new VerificationEvent('ref-2', VerificationEvent::PENDING);
            }
            public function fetchStatus(string $ref): VerificationEvent
            {
                return new VerificationEvent($ref, VerificationEvent::PENDING);
            }
            public function capabilities(): array { return ['webhook']; }
            public function name(): string { return 'fake-pending'; }
        });
        $called = false;
        IdentityVerificationService::onDecisionUsing(function () use (&$called): void {
            $called = true;
        });
        (new IdentityVerificationService())->handleWebhook('fake-pending', '{}', []);
        $this->assertFalse($called);
    }

    public function testHandleWebhookIsANoOpWithoutARegisteredHook(): void
    {
        IdentityProviderFactory::extend('fake-verified', fn () => new class implements IdentityVerificationProvider {
            public function createSession(int $userId, string $returnUrl): VerificationSession
            {
                return new VerificationSession('fake-verified', '', 'manual');
            }
            public function verifyWebhook(string $payload, array $headers): VerificationEvent
            {
                return new VerificationEvent('ref-3', VerificationEvent::VERIFIED);
            }
            public function fetchStatus(string $ref): VerificationEvent
            {
                return new VerificationEvent($ref, VerificationEvent::PENDING);
            }
            public function capabilities(): array { return ['webhook']; }
            public function name(): string { return 'fake-verified'; }
        });
        (new IdentityVerificationService())->handleWebhook('fake-verified', '{}', []);
        $this->addToAssertionCount(1); // must not throw
    }
}
