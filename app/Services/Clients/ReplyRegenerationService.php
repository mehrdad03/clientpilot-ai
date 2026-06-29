<?php

namespace App\Services\Clients;

use App\Enums\BotSuggestionStatus;
use App\Models\BotSuggestion;
use Illuminate\Support\Facades\DB;

class ReplyRegenerationService
{
    public function __construct(
        private readonly ReplySuggestionService $replySuggestionService,
    ) {}

    /**
     * @return array{status: string, suggestion: BotSuggestion|null}
     */
    public function latestForUser(int|string $telegramUserId, int|string|null $activeClientId): array
    {
        if ($activeClientId === null || $activeClientId === '') {
            return $this->result('missing_active_client');
        }

        $suggestion = BotSuggestion::query()
            ->where('telegram_user_id', $telegramUserId)
            ->where('client_id', $activeClientId)
            ->latest('id')
            ->first();

        return $this->classifySuggestion($suggestion);
    }

    /**
     * @return array{status: string, suggestion: BotSuggestion|null}
     */
    public function forSuggestion(int|string $telegramUserId, int|string $botSuggestionId): array
    {
        $suggestion = BotSuggestion::query()
            ->where('id', $botSuggestionId)
            ->where('telegram_user_id', $telegramUserId)
            ->first();

        return $this->classifySuggestion($suggestion);
    }

    /**
     * @return array{client_id: int, telegram_user_id: int}|null
     */
    public function jobContext(int|string $botSuggestionId): ?array
    {
        $suggestion = BotSuggestion::query()->find($botSuggestionId);

        if ($suggestion === null) {
            return null;
        }

        return [
            'client_id' => (int) $suggestion->client_id,
            'telegram_user_id' => (int) $suggestion->telegram_user_id,
        ];
    }

    /**
     * @param  array<string, string|null>  $jobContext
     * @return array{status: string, old_suggestion: BotSuggestion|null, suggestion: BotSuggestion|null, options: array<int, mixed>, message: string|null}
     */
    public function regenerate(int|string $botSuggestionId, array $jobContext = []): array
    {
        $oldSuggestion = BotSuggestion::query()->find($botSuggestionId);
        $classification = $this->classifySuggestion($oldSuggestion);

        if ($classification['status'] !== 'ready' || $oldSuggestion === null) {
            return [
                'status' => $classification['status'],
                'old_suggestion' => $oldSuggestion,
                'suggestion' => null,
                'options' => [],
                'message' => null,
            ];
        }

        $generated = $this->replySuggestionService->generateForClientMessage(
            $oldSuggestion->client_id,
            $oldSuggestion->conversation_message_id,
            $jobContext
        );

        return DB::transaction(function () use ($oldSuggestion, $generated): array {
            $lockedOldSuggestion = BotSuggestion::query()
                ->where('id', $oldSuggestion->id)
                ->lockForUpdate()
                ->first();

            $classification = $this->classifySuggestion($lockedOldSuggestion);

            if ($classification['status'] !== 'ready') {
                $this->discardGeneratedSuggestion($generated['suggestion']);

                return [
                    'status' => $classification['status'],
                    'old_suggestion' => $lockedOldSuggestion,
                    'suggestion' => null,
                    'options' => [],
                    'message' => null,
                ];
            }

            $lockedOldSuggestion->update([
                'status' => BotSuggestionStatus::Regenerated->value,
                'updated_at' => now(),
            ]);

            return [
                'status' => 'regenerated',
                'old_suggestion' => $lockedOldSuggestion->refresh(),
                'suggestion' => $generated['suggestion'],
                'options' => $generated['options'],
                'message' => $generated['message'],
            ];
        });
    }

    /**
     * @return array{status: string, suggestion: BotSuggestion|null}
     */
    private function classifySuggestion(?BotSuggestion $suggestion): array
    {
        if ($suggestion === null) {
            return $this->result('missing_suggestion');
        }

        if ($suggestion->status === BotSuggestionStatus::Selected->value || $suggestion->selected_option_id !== null) {
            return $this->result('already_selected', $suggestion);
        }

        if ($suggestion->status !== BotSuggestionStatus::Generated->value) {
            return $this->result('stale', $suggestion);
        }

        return $this->result('ready', $suggestion);
    }

    private function discardGeneratedSuggestion(BotSuggestion $suggestion): void
    {
        DB::table('bot_suggestion_options')
            ->where('bot_suggestion_id', $suggestion->id)
            ->delete();

        $suggestion->delete();
    }

    /**
     * @return array{status: string, suggestion: BotSuggestion|null}
     */
    private function result(string $status, ?BotSuggestion $suggestion = null): array
    {
        return [
            'status' => $status,
            'suggestion' => $suggestion,
        ];
    }
}
