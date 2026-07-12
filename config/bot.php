<?php
declare(strict_types=1);

return [
    // Telegram bot token (from @BotFather). Used to sign/verify Web App initData.
    'token' => env('TELEGRAM_BOT_TOKEN', ''),

    // The secret_token set via setWebhook — Telegram echoes it back in the
    // X-Telegram-Bot-Api-Secret-Token header on every webhook delivery.
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET', ''),

    // Comma-separated Telegram user ids allowed to run AdminBotController commands.
    'admin_ids' => env('TELEGRAM_ADMIN_IDS', ''),
];
