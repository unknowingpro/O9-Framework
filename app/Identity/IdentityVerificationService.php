<?php
declare(strict_types=1);

namespace App\Identity;

use App\Identity\Dto\VerificationEvent;
use App\Identity\Dto\VerificationSession;
use RuntimeException;

/**
 * Orchestrates identity verification across the configured provider. Resolves
 * the active mode, starts a session (manual form or hosted redirect), and
 * bridges a provider webhook decision onto the app's user state.
 *
 * The framework doesn't know how an app records a KYC submission or applies a
 * verified/rejected decision (that's app-specific storage). Apps register
 * both via injectable hooks, typically in app/bootstrap.php:
 *
 *   IdentityVerificationService::onSessionOpenedUsing(
 *       fn (int $userId, string $provider, string $ref) => (new KycService())->openProviderSubmission($userId, $provider, $ref)
 *   );
 *   IdentityVerificationService::onDecisionUsing(
 *       fn (string $provider, string $ref, bool $verified, ?string $reason) => (new KycService())->resolveByProviderRef($provider, $ref, $verified, $reason)
 *   );
 *
 * Without registered hooks, sessions still open and webhooks still verify —
 * only the "reflect the decision onto the user" step is a no-op.
 */
final class IdentityVerificationService
{
    /** @var (callable(int, string, string): void)|null */
    private static $onSessionOpened = null;
    /** @var (callable(string, string, bool, ?string): void)|null */
    private static $onDecision = null;

    /** @param (callable(int, string, string): void)|null $fn */
    public static function onSessionOpenedUsing(?callable $fn): void
    {
        self::$onSessionOpened = $fn;
    }

    /** @param (callable(string, string, bool, ?string): void)|null $fn */
    public static function onDecisionUsing(?callable $fn): void
    {
        self::$onDecision = $fn;
    }

    public function activeMode(): string
    {
        return IdentityProviderFactory::active();
    }

    /** Begin verification for the active mode. 'off' throws; manual returns the form. */
    public function start(int $userId, string $returnUrl): VerificationSession
    {
        $mode = IdentityProviderFactory::active();
        if ($mode === 'off') {
            throw new RuntimeException(__('identity.unavailable'));
        }
        $provider = IdentityProviderFactory::make($mode);
        $session  = $provider->createSession($userId, $returnUrl);
        if ($session->mode === 'redirect' && $session->ref !== '' && self::$onSessionOpened !== null) {
            (self::$onSessionOpened)($userId, $provider->name(), $session->ref);
        }
        return $session;
    }

    /**
     * Verify + apply an async provider webhook. Pending decisions are ignored.
     *
     * @param array<string, string> $headers
     */
    public function handleWebhook(string $provider, string $payload, array $headers): void
    {
        $event = IdentityProviderFactory::make($provider)->verifyWebhook($payload, $headers);
        if ($event->status === VerificationEvent::PENDING) {
            return;
        }
        if (self::$onDecision !== null) {
            (self::$onDecision)(
                $provider,
                $event->ref,
                $event->status === VerificationEvent::VERIFIED,
                $event->reason
            );
        }
    }

    /** @internal test reset */
    public static function reset(): void
    {
        self::$onSessionOpened = null;
        self::$onDecision = null;
    }
}
