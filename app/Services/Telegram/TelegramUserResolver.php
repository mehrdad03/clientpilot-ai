<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\DB;

class TelegramUserResolver
{
    /**
     * @param  array<string, mixed>  $from
     * @return array<string, mixed>|null
     */
    public function resolve(array $from, int|string $chatId): ?array
    {
        $telegramUserId = data_get($from, 'id');

        if ($telegramUserId === null) {
            return null;
        }

        $isAllowed = $this->isAllowed($telegramUserId);
        $now = now();

        DB::table('telegram_users')->upsert([
            [
                'telegram_user_id' => $telegramUserId,
                'chat_id' => $chatId,
                'username' => data_get($from, 'username'),
                'first_name' => data_get($from, 'first_name'),
                'last_name' => data_get($from, 'last_name'),
                'language_code' => data_get($from, 'language_code'),
                'is_allowed' => $isAllowed,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['telegram_user_id'], [
            'chat_id',
            'username',
            'first_name',
            'last_name',
            'language_code',
            'is_allowed',
            'last_seen_at',
            'updated_at',
        ]);

        if (! $isAllowed) {
            return null;
        }

        $user = DB::table('telegram_users')
            ->where('telegram_user_id', $telegramUserId)
            ->first();

        return $user === null ? null : (array) $user;
    }

    public function isAllowed(int|string $telegramUserId): bool
    {
        $allowedUserIds = array_map('strval', (array) config('telegram.allowed_user_ids', []));

        return in_array((string) $telegramUserId, $allowedUserIds, true);
    }
}
