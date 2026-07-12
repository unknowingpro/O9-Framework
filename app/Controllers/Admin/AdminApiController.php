<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;

/** Auth-gated admin API sample — register behind ['Auth:admin']. */
final class AdminApiController extends BaseController
{
    public function whoami(Request $request): never
    {
        Response::ok(['user' => self::sanitize($this->user())]);
    }

    /**
     * Auth::user() returns whatever row shape the app's resolveUserUsing() hook
     * resolves — very plausibly the raw users row, password_hash and all. This
     * sample endpoint is the pattern apps copy, so it must not model "just echo
     * the row back" as safe.
     *
     * @param array<string, mixed>|null $user
     * @return array<string, mixed>|null
     */
    private static function sanitize(?array $user): ?array
    {
        unset($user['password_hash']);
        return $user;
    }
}
