<?php

namespace App\Services\Clients;

use App\Models\ClientSummary;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MemorySummaryService
{
    public function __construct(
        private readonly ClientSummaryBuilder $summaryBuilder,
    ) {}

    /**
     * @param  array<string, string|null>  $jobContext
     */
    public function updateForClient(int|string $clientId, array $jobContext = []): ClientSummary
    {
        $client = DB::table('clients')->where('id', $clientId)->first();

        if ($client === null) {
            throw new RuntimeException("Client [{$clientId}] was not found.");
        }

        $result = $this->summaryBuilder->buildForClient($clientId, $jobContext);

        return ClientSummary::query()->updateOrCreate(
            ['client_id' => $client->id],
            array_merge($result['summary'], [
                'telegram_user_id' => $client->telegram_user_id,
                'ai_request_id' => $result['ai_request']->id,
                'last_message_id' => $result['last_message_id'],
            ])
        );
    }

    /**
     * @return array{client: array<string, mixed>, summary: ClientSummary|null}|null
     */
    public function summaryForActiveClient(int|string $telegramUserId, int|string|null $activeClientId): ?array
    {
        if ($activeClientId === null || $activeClientId === '') {
            return null;
        }

        $client = DB::table('clients')
            ->where('id', $activeClientId)
            ->where('telegram_user_id', $telegramUserId)
            ->first();

        if ($client === null) {
            return null;
        }

        return [
            'client' => (array) $client,
            'summary' => ClientSummary::query()
                ->where('client_id', $client->id)
                ->where('telegram_user_id', $telegramUserId)
                ->first(),
        ];
    }
}
