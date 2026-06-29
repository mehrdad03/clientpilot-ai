<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\DB;

class TelegramStateManager
{
    /**
     * @return array{state: string|null, payload: array<string, mixed>, active_client_id: int|null}|null
     */
    public function getState(int|string $telegramUserId): ?array
    {
        $state = DB::table('telegram_user_states')
            ->where('telegram_user_id', $telegramUserId)
            ->first();

        if ($state === null) {
            return null;
        }

        return [
            'state' => $state->state,
            'payload' => $this->decodePayload($state->payload),
            'active_client_id' => $state->active_client_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function setState(int|string $telegramUserId, ?string $state, array $payload = [], int|string|null $activeClientId = null): void
    {
        $now = now();

        DB::table('telegram_user_states')->upsert([
            [
                'telegram_user_id' => $telegramUserId,
                'state' => $state,
                'payload' => $this->encodePayload($payload),
                'active_client_id' => $activeClientId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['telegram_user_id'], [
            'state',
            'payload',
            'active_client_id',
            'updated_at',
        ]);
    }

    public function setActiveClient(int|string $telegramUserId, int|string|null $activeClientId): void
    {
        $currentState = $this->getState($telegramUserId);

        $this->setState(
            $telegramUserId,
            $currentState['state'] ?? null,
            $currentState['payload'] ?? [],
            $activeClientId
        );
    }

    public function clearConversationState(int|string $telegramUserId): void
    {
        $currentState = $this->getState($telegramUserId);

        $this->setState(
            $telegramUserId,
            null,
            [],
            $currentState['active_client_id'] ?? null
        );
    }

    public function clearState(int|string $telegramUserId): void
    {
        DB::table('telegram_user_states')
            ->where('telegram_user_id', $telegramUserId)
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(?string $payload): array
    {
        if ($payload === null || $payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodePayload(array $payload): string
    {
        if ($payload === []) {
            return '{}';
        }

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
