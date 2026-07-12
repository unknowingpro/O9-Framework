<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;

/**
 * Force-update gate for native clients. Inert by default (master switch
 * config('mobile.version_gate.enabled') off) and only ever fires for ios/
 * android requests that send an X-App-Version below the configured minimum
 * — web, unknown platforms, and header-less callers (health, legacy) pass
 * straight through. Register on the whole /api/v1 group so a too-old
 * client is told to update even at the login screen.
 */
final class VersionGate implements Middleware
{
    public function handle(Request $request, ?string $arg = null): void
    {
        $platform = $request->platform();
        $enabled  = (bool) config('mobile.version_gate.enabled', false);
        $min      = (string) config("mobile.version_gate.min_version.{$platform}", '0.0.0');
        if (!self::blocks($enabled, $platform, $request->appVersion(), $min)) {
            return;
        }
        throw HttpException::forceUpdate(__('mobile.force_update'), [
            'platform'    => $platform,
            'min_version' => $min,
            'update_url'  => (string) config("mobile.version_gate.update_url.{$platform}", ''),
        ]);
    }

    /**
     * Pure decision (no exit) so it's unit-testable. Blocks only when the
     * gate is enabled, the platform is native, a version header is present,
     * and that version is older than the minimum.
     */
    public static function blocks(bool $enabled, string $platform, string $current, string $min): bool
    {
        if (!$enabled) {
            return false;
        }
        if ($platform !== 'ios' && $platform !== 'android') {
            return false;
        }
        if ($current === '') {
            return false;
        }
        return version_compare($current, $min, '<');
    }
}
