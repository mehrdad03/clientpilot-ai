<?php

$allowedUserIds = array_values(array_filter(
    array_map(
        static fn (string $id): string => trim($id),
        explode(',', (string) env('TELEGRAM_ALLOWED_USER_IDS', ''))
    ),
    static fn (string $id): bool => $id !== ''
));

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'allowed_user_ids' => $allowedUserIds,
];
