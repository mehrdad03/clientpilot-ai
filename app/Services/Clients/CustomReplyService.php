<?php

namespace App\Services\Clients;

use App\Enums\ClientStage;
use App\Enums\ClientStatus;
use App\Enums\ConversationMessageType;
use App\Enums\ConversationSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomReplyService
{
    /**
     * @return array{client_id: int, suggestion_id: int|null}|null
     */
    public function contextForCustomReply(
        int|string $telegramUserId,
        int|string|null $activeClientId,
        int|string|null $suggestionId = null
    ): ?array
    {
        if ($suggestionId !== null && $suggestionId !== '') {
            $suggestion = DB::table('bot_suggestions')
                ->where('id', $suggestionId)
                ->where('telegram_user_id', $telegramUserId)
                ->first();

            if ($suggestion === null) {
                return null;
            }

            $client = $this->findActiveClient($telegramUserId, $suggestion->client_id);

            if ($client === null) {
                return null;
            }

            return [
                'client_id' => (int) $client->id,
                'suggestion_id' => (int) $suggestion->id,
            ];
        }

        $client = $this->findActiveClient($telegramUserId, $activeClientId);

        if ($client === null) {
            return null;
        }

        return [
            'client_id' => (int) $client->id,
            'suggestion_id' => $this->latestSuggestionId($telegramUserId, $client->id),
        ];
    }

    /**
     * @return array{client: array<string, mixed>, message: array<string, mixed>, analysis: array<string, mixed>}|null
     */
    public function storeCustomReply(
        int|string $telegramUserId,
        int|string|null $clientId,
        int|string|null $suggestionId,
        string $body
    ): ?array {
        $client = $this->findActiveClient($telegramUserId, $clientId);

        if ($client === null) {
            return null;
        }

        $validSuggestionId = $this->validSuggestionId($telegramUserId, $client->id, $suggestionId);
        $analysis = $this->analyzeReply($body);

        return DB::transaction(function () use ($telegramUserId, $client, $validSuggestionId, $body, $analysis): array {
            $now = now();
            $metadata = $validSuggestionId === null ? null : json_encode([
                'suggestion_id' => $validSuggestionId,
            ], JSON_THROW_ON_ERROR);

            $messageId = DB::table('conversation_messages')->insertGetId([
                'client_id' => $client->id,
                'telegram_user_id' => $telegramUserId,
                'sender' => ConversationSender::Mehrdad->value,
                'message_type' => ConversationMessageType::CustomReply->value,
                'body' => $body,
                'metadata' => $metadata,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($this->isValidStage($analysis['next_stage']) && $client->stage !== $analysis['next_stage']) {
                DB::table('clients')
                    ->where('id', $client->id)
                    ->update([
                        'stage' => $analysis['next_stage'],
                        'updated_at' => $now,
                    ]);
            } else {
                DB::table('clients')
                    ->where('id', $client->id)
                    ->update(['updated_at' => $now]);
            }

            return [
                'client' => (array) DB::table('clients')->where('id', $client->id)->first(),
                'message' => (array) DB::table('conversation_messages')->where('id', $messageId)->first(),
                'analysis' => $analysis,
            ];
        });
    }

    private function findActiveClient(int|string $telegramUserId, int|string|null $clientId): ?object
    {
        if ($clientId === null || $clientId === '') {
            return null;
        }

        $client = DB::table('clients')
            ->where('id', $clientId)
            ->where('telegram_user_id', $telegramUserId)
            ->first();

        if ($client === null || $client->status === ClientStatus::Closed->value) {
            return null;
        }

        return $client;
    }

    private function latestSuggestionId(int|string $telegramUserId, int|string $clientId): ?int
    {
        $suggestionId = DB::table('bot_suggestions')
            ->where('telegram_user_id', $telegramUserId)
            ->where('client_id', $clientId)
            ->latest('id')
            ->value('id');

        return $suggestionId === null ? null : (int) $suggestionId;
    }

    private function validSuggestionId(int|string $telegramUserId, int|string $clientId, int|string|null $suggestionId): ?int
    {
        if ($suggestionId === null || $suggestionId === '') {
            return null;
        }

        $exists = DB::table('bot_suggestions')
            ->where('id', $suggestionId)
            ->where('telegram_user_id', $telegramUserId)
            ->where('client_id', $clientId)
            ->exists();

        return $exists ? (int) $suggestionId : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeReply(string $body): array
    {
        $normalized = Str::of($body)->lower()->toString();

        $pricingDiscussed = $this->containsAny($normalized, [
            '$',
            'usd',
            'dollar',
            'price',
            'pricing',
            'cost',
            'budget',
            'rate',
            'fee',
            'payment',
            'milestone',
            'fixed',
            'hourly',
            'discount',
        ]);

        $deadlineDiscussed = $this->containsAny($normalized, [
            'deadline',
            'deliver',
            'delivery',
            'today',
            'tomorrow',
            'tonight',
            'friday',
            'monday',
            'tuesday',
            'wednesday',
            'thursday',
            'saturday',
            'sunday',
            'asap',
            'eod',
            'within ',
        ]);

        $promisesMade = $this->containsAny($normalized, [
            'i will',
            "i'll",
            'i can deliver',
            'i can finish',
            'guarantee',
            'promise',
            'definitely',
            'for sure',
            '100%',
            'unlimited revisions',
        ]);

        $accessNeeded = $this->containsAny($normalized, [
            'access',
            'login',
            'credentials',
            'password',
            'admin',
            'invite',
            'repository',
            'repo',
            'github',
            'hosting',
            'server',
            'api key',
        ]);

        return [
            'pricing_discussed' => $pricingDiscussed,
            'deadline_discussed' => $deadlineDiscussed,
            'promises_made' => $promisesMade,
            'access_needed' => $accessNeeded,
            'risk_level' => $this->riskLevel($normalized, $pricingDiscussed, $deadlineDiscussed, $promisesMade, $accessNeeded),
            'next_stage' => ClientStage::Chatting->value,
        ];
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function riskLevel(string $body, bool $pricingDiscussed, bool $deadlineDiscussed, bool $promisesMade, bool $accessNeeded): string
    {
        if ($this->containsAny($body, ['guarantee', 'unlimited revisions', '100%', 'refund', 'free work', 'password'])) {
            return 'high';
        }

        if (($deadlineDiscussed && $promisesMade) || ($pricingDiscussed && $promisesMade)) {
            return 'high';
        }

        if ($pricingDiscussed || $deadlineDiscussed || $promisesMade || $accessNeeded) {
            return 'medium';
        }

        return 'low';
    }

    private function isValidStage(mixed $stage): bool
    {
        if (! is_string($stage)) {
            return false;
        }

        return in_array($stage, array_map(
            static fn (ClientStage $clientStage): string => $clientStage->value,
            ClientStage::cases()
        ), true);
    }
}
