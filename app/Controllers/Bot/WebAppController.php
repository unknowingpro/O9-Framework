<?php
declare(strict_types=1);

namespace App\Controllers\Bot;

use App\Core\BaseController;
use App\Core\InitDataValidator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security\Jwt;

/**
 * Telegram Web App (Mini App) auth: validates the client's initData HMAC
 * signature (see Core\InitDataValidator) and, on success, issues an access
 * JWT the Mini App uses for subsequent API calls — the same token shape
 * Middleware\Auth expects.
 */
final class WebAppController extends BaseController
{
    public function auth(Request $request): never
    {
        $initData = (string) $request->input('initData', '');
        $botToken = (string) config('bot.token', '');

        if (!InitDataValidator::validate($initData, $botToken)) {
            Response::unauthorized('Invalid Telegram Web App signature.');
        }

        $fields = InitDataValidator::parse($initData);
        $tgUser = json_decode((string) ($fields['user'] ?? '{}'), true);
        $tgId = (int) (is_array($tgUser) ? ($tgUser['id'] ?? 0) : 0);
        if ($tgId <= 0) {
            Response::unauthorized('Missing Telegram user in initData.');
        }

        // App-specific: resolve/create the local user for this Telegram id here.
        $userId = $tgId;

        $token = Jwt::encode(['sub' => $userId, 'typ' => 'access', 'src' => 'telegram'], (int) config('app.jwt.ttl', 86400));
        Response::ok(['token' => $token, 'user_id' => $userId]);
    }
}
