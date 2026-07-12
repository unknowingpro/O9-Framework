<?php
declare(strict_types=1);

namespace App\Controllers\Bot;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;

/**
 * Telegram bot webhook receiver. Verifies the secret token Telegram echoes
 * back on every update (set via setWebhook's secret_token parameter) before
 * processing anything, then hands recognized admin commands off to
 * AdminBotController. No Telegram client library is bundled — sending
 * replies is left to your bot library of choice (e.g. danog/madelineproto),
 * wired in where marked below.
 */
final class WebhookController extends BaseController
{
    public function receive(Request $request): never
    {
        $expected = (string) config('bot.webhook_secret', '');
        if ($expected !== '' && !hash_equals($expected, $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            Response::unauthorized('Invalid webhook secret.');
        }

        $update = $request->all();
        $text = (string) ($update['message']['text'] ?? '');
        $chatId = $update['message']['chat']['id'] ?? null;
        $fromId = $update['message']['from']['id'] ?? null;

        if ($text !== '' && str_starts_with($text, '/') && $fromId !== null) {
            $reply = (new AdminBotController())->dispatch((int) $fromId, $text);
            if ($reply !== null && $chatId !== null) {
                // Send $reply to $chatId via your bot library here.
            }
        }

        // Telegram only requires a 200 response; the envelope is for parity
        // with the rest of the API.
        Response::ok(['received' => true]);
    }
}
