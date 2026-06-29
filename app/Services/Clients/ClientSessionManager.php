<?php

namespace App\Services\Clients;

use App\Enums\ClientStage;
use App\Enums\ClientStatus;
use App\Enums\ConversationMessageType;
use App\Enums\ConversationSender;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientSessionManager
{
    public const STATE_WAITING_FOR_NEW_CLIENT_JOB = 'waiting_for_new_client_job';

    public const STATE_WAITING_FOR_FEEDBACK_REASON = 'waiting_for_feedback_reason';

    public const STATE_WAITING_FOR_CUSTOM_REPLY = 'waiting_for_custom_reply';

    public const STATE_CHATTING_WITH_CLIENT = 'chatting_with_client';

    /**
     * @return array<string, mixed>
     */
    public function createClientFromInitialMessage(int|string $telegramUserId, string $initialMessage): array
    {
        return DB::transaction(function () use ($telegramUserId, $initialMessage): array {
            $now = now();
            $clientId = DB::table('clients')->insertGetId([
                'telegram_user_id' => $telegramUserId,
                'title' => $this->makeTitle($initialMessage),
                'status' => ClientStatus::Active->value,
                'stage' => ClientStage::Intake->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('conversation_messages')->insert([
                'client_id' => $clientId,
                'telegram_user_id' => $telegramUserId,
                'sender' => ConversationSender::Client->value,
                'message_type' => ConversationMessageType::InitialJob->value,
                'body' => $initialMessage,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->findOwnedClient($telegramUserId, $clientId) ?? [];
        });
    }

    /**
     * @return Collection<int, object>
     */
    public function listClients(int|string $telegramUserId): Collection
    {
        return DB::table('clients')
            ->where('telegram_user_id', $telegramUserId)
            ->orderByRaw('case when status = ? then 1 else 0 end', [ClientStatus::Closed->value])
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getActiveClient(int|string $telegramUserId, int|string|null $activeClientId): ?array
    {
        if ($activeClientId === null || $activeClientId === '') {
            return null;
        }

        return $this->findOwnedClient($telegramUserId, $activeClientId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resumeClient(int|string $telegramUserId, int|string|null $activeClientId): ?array
    {
        $client = $this->getActiveClient($telegramUserId, $activeClientId);

        if ($client === null || $client['status'] === ClientStatus::Closed->value) {
            return null;
        }

        DB::table('clients')
            ->where('id', $client['id'])
            ->where('telegram_user_id', $telegramUserId)
            ->update([
                'status' => ClientStatus::Active->value,
                'paused_at' => null,
                'updated_at' => now(),
            ]);

        return $this->findOwnedClient($telegramUserId, $client['id']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pauseClient(int|string $telegramUserId, int|string|null $activeClientId): ?array
    {
        $client = $this->getActiveClient($telegramUserId, $activeClientId);

        if ($client === null || $client['status'] === ClientStatus::Closed->value) {
            return null;
        }

        DB::table('clients')
            ->where('id', $client['id'])
            ->where('telegram_user_id', $telegramUserId)
            ->update([
                'status' => ClientStatus::Paused->value,
                'paused_at' => now(),
                'updated_at' => now(),
            ]);

        return $this->findOwnedClient($telegramUserId, $client['id']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function closeClient(int|string $telegramUserId, int|string|null $activeClientId): ?array
    {
        $client = $this->getActiveClient($telegramUserId, $activeClientId);

        if ($client === null) {
            return null;
        }

        DB::table('clients')
            ->where('id', $client['id'])
            ->where('telegram_user_id', $telegramUserId)
            ->update([
                'status' => ClientStatus::Closed->value,
                'closed_at' => now(),
                'updated_at' => now(),
            ]);

        return $this->findOwnedClient($telegramUserId, $client['id']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function startChatting(int|string $telegramUserId, int|string|null $activeClientId): ?array
    {
        $client = $this->getActiveClient($telegramUserId, $activeClientId);

        if ($client === null || $client['status'] === ClientStatus::Closed->value) {
            return null;
        }

        if (! in_array($client['stage'], [ClientStage::Analyzed->value, ClientStage::Chatting->value], true)) {
            return null;
        }

        DB::table('clients')
            ->where('id', $client['id'])
            ->where('telegram_user_id', $telegramUserId)
            ->update([
                'stage' => ClientStage::Chatting->value,
                'updated_at' => now(),
            ]);

        return $this->findOwnedClient($telegramUserId, $client['id']);
    }

    /**
     * @return array{client: array<string, mixed>, message: array<string, mixed>}|null
     */
    public function storeClientMessage(int|string $telegramUserId, int|string|null $activeClientId, string $body): ?array
    {
        $client = $this->getActiveClient($telegramUserId, $activeClientId);

        if ($client === null || $client['status'] === ClientStatus::Closed->value) {
            return null;
        }

        return DB::transaction(function () use ($telegramUserId, $client, $body): array {
            $now = now();
            $messageId = DB::table('conversation_messages')->insertGetId([
                'client_id' => $client['id'],
                'telegram_user_id' => $telegramUserId,
                'sender' => ConversationSender::Client->value,
                'message_type' => ConversationMessageType::ClientMessage->value,
                'body' => $body,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('clients')
                ->where('id', $client['id'])
                ->update(['updated_at' => $now]);

            return [
                'client' => $this->findOwnedClient($telegramUserId, $client['id']) ?? $client,
                'message' => (array) DB::table('conversation_messages')->where('id', $messageId)->first(),
            ];
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findOwnedClient(int|string $telegramUserId, int|string $clientId): ?array
    {
        $client = DB::table('clients')
            ->where('id', $clientId)
            ->where('telegram_user_id', $telegramUserId)
            ->first();

        return $client === null ? null : (array) $client;
    }

    private function makeTitle(string $message): string
    {
        $title = Str::of($message)
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->limit(70, '');

        return $title->isEmpty() ? 'Untitled Client' : $title->toString();
    }
}
