<?php

namespace App\Services\Clients;

use App\Enums\BotSuggestionStatus;
use App\Enums\ConversationMessageType;
use App\Enums\ConversationSender;
use App\Models\BotSuggestion;
use App\Models\BotSuggestionOption;
use Illuminate\Support\Facades\DB;

class SentReplySelectionService
{
    /**
     * @return array{status: string, suggestion: BotSuggestion|null, option: BotSuggestionOption|null}
     */
    public function selectLatestOption(int|string $telegramUserId, int|string|null $activeClientId, int $optionNumber): array
    {
        if ($activeClientId === null || $activeClientId === '') {
            return $this->result('missing_active_client');
        }

        return DB::transaction(function () use ($telegramUserId, $activeClientId, $optionNumber): array {
            $suggestion = BotSuggestion::query()
                ->where('telegram_user_id', $telegramUserId)
                ->where('client_id', $activeClientId)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            return $this->selectSuggestionOption($suggestion, $optionNumber);
        });
    }

    /**
     * @return array{status: string, suggestion: BotSuggestion|null, option: BotSuggestionOption|null}
     */
    public function selectOption(int|string $telegramUserId, int|string $botSuggestionId, int $optionNumber): array
    {
        return DB::transaction(function () use ($telegramUserId, $botSuggestionId, $optionNumber): array {
            $suggestion = BotSuggestion::query()
                ->where('id', $botSuggestionId)
                ->where('telegram_user_id', $telegramUserId)
                ->lockForUpdate()
                ->first();

            return $this->selectSuggestionOption($suggestion, $optionNumber);
        });
    }

    /**
     * @return array{status: string, suggestion: BotSuggestion|null, option: BotSuggestionOption|null}
     */
    private function selectSuggestionOption(?BotSuggestion $suggestion, int $optionNumber): array
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

        $option = BotSuggestionOption::query()
            ->where('bot_suggestion_id', $suggestion->id)
            ->where('option_number', $optionNumber)
            ->first();

        if ($option === null) {
            return $this->result('missing_option', $suggestion);
        }

        $now = now();

        $suggestion->update([
            'status' => BotSuggestionStatus::Selected->value,
            'selected_option_id' => $option->id,
            'selected_text' => $option->body,
            'selected_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('conversation_messages')->insert([
            'client_id' => $suggestion->client_id,
            'telegram_user_id' => $suggestion->telegram_user_id,
            'sender' => ConversationSender::Mehrdad->value,
            'message_type' => ConversationMessageType::SelectedReply->value,
            'body' => $option->body,
            'metadata' => json_encode([
                'suggestion_id' => $suggestion->id,
                'option_id' => $option->id,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->result('selected', $suggestion->refresh(), $option);
    }

    /**
     * @return array{status: string, suggestion: BotSuggestion|null, option: BotSuggestionOption|null}
     */
    private function result(string $status, ?BotSuggestion $suggestion = null, ?BotSuggestionOption $option = null): array
    {
        return [
            'status' => $status,
            'suggestion' => $suggestion,
            'option' => $option,
        ];
    }
}
