<?php

namespace Tests\Feature;

use App\Enums\BotSuggestionStatus;
use App\Enums\ClientStage;
use App\Enums\ClientStatus;
use App\Enums\ConversationMessageType;
use App\Enums\ConversationSender;
use App\Services\Clients\ClientSessionManager;
use App\Services\Telegram\TelegramMessageFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TelegramInlineCallbackMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.bot_token' => 'test-token',
            'telegram.webhook_secret' => 'test-secret',
            'telegram.allowed_user_ids' => ['123456', '777777'],
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
    }

    public function test_my_clients_uses_inline_client_action_callbacks(): void
    {
        $clientId = $this->createClient(stage: ClientStage::Chatting->value);

        $this->sendTelegramText(1, TelegramMessageFormatter::BUTTON_MY_CLIENTS)->assertOk();

        Http::assertSent(function ($request) use ($clientId): bool {
            $data = $request->data();

            return str_contains((string) data_get($data, 'text'), "#{$clientId}")
                && data_get($data, 'reply_markup.inline_keyboard.0.0.callback_data') === "cl:rs:{$clientId}"
                && data_get($data, 'reply_markup.inline_keyboard.0.1.callback_data') === "cl:pa:{$clientId}"
                && data_get($data, 'reply_markup.inline_keyboard.1.0.callback_data') === "cl:cl:{$clientId}"
                && data_get($data, 'reply_markup.inline_keyboard.1.1.callback_data') === "cl:sum:{$clientId}";
        });
    }

    public function test_start_chat_callback_answers_query_and_routes_existing_logic(): void
    {
        $clientId = $this->createClient(stage: ClientStage::Analyzed->value);

        $this->sendTelegramCallback(2, "chat:start:{$clientId}")->assertOk();

        $this->assertDatabaseHas('telegram_user_states', [
            'telegram_user_id' => 123456,
            'state' => ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
            'active_client_id' => $clientId,
        ]);

        $this->assertDatabaseHas('clients', [
            'id' => $clientId,
            'stage' => ClientStage::Chatting->value,
        ]);

        $this->assertCallbackAnswered('cb_2');
    }

    public function test_client_callbacks_check_ownership(): void
    {
        $clientId = $this->createClient(stage: ClientStage::Chatting->value);

        $this->sendTelegramCallback(3, "cl:pa:{$clientId}", 777777)->assertOk();

        $this->assertDatabaseHas('clients', [
            'id' => $clientId,
            'status' => ClientStatus::Active->value,
        ]);

        $this->assertCallbackAnswered('cb_3');

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->noActiveClientMessage();
        });
    }

    public function test_sent_option_callback_selects_specific_owned_suggestion(): void
    {
        $fixture = $this->createSuggestionFixture();

        $this->sendTelegramCallback(4, "sg:sel:{$fixture['suggestion_id']}:2")->assertOk();

        $this->assertDatabaseHas('bot_suggestions', [
            'id' => $fixture['suggestion_id'],
            'status' => BotSuggestionStatus::Selected->value,
            'selected_option_id' => $fixture['option_2_id'],
            'selected_text' => 'Professional reply text.',
        ]);

        $this->assertSame(1, DB::table('conversation_messages')
            ->where('client_id', $fixture['client_id'])
            ->where('message_type', ConversationMessageType::SelectedReply->value)
            ->count());

        $this->assertCallbackAnswered('cb_4');
    }

    public function test_suggestion_callbacks_set_feedback_and_custom_reply_states(): void
    {
        $fixture = $this->createSuggestionFixture();

        $this->sendTelegramCallback(5, "sg:fb:{$fixture['suggestion_id']}")->assertOk();

        $feedbackState = DB::table('telegram_user_states')->where('telegram_user_id', 123456)->first();

        $this->assertSame(ClientSessionManager::STATE_WAITING_FOR_FEEDBACK_REASON, $feedbackState->state);
        $this->assertSame(['bot_suggestion_id' => $fixture['suggestion_id']], json_decode($feedbackState->payload, true));

        $this->sendTelegramCallback(6, "sg:custom:{$fixture['suggestion_id']}")->assertOk();

        $customState = DB::table('telegram_user_states')->where('telegram_user_id', 123456)->first();

        $this->assertSame(ClientSessionManager::STATE_WAITING_FOR_CUSTOM_REPLY, $customState->state);
        $this->assertSame([
            'client_id' => $fixture['client_id'],
            'suggestion_id' => $fixture['suggestion_id'],
        ], json_decode($customState->payload, true));

        $this->assertCallbackAnswered('cb_5');
        $this->assertCallbackAnswered('cb_6');
    }

    public function test_invalid_callback_is_answered_and_safe(): void
    {
        $this->sendTelegramCallback(7, 'sg:sel:not-valid:1')->assertOk();

        $this->assertCallbackAnswered('cb_7');

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->staleSuggestionMessage();
        });
    }

    /**
     * @return array{client_id: int, suggestion_id: int, option_1_id: int, option_2_id: int, option_3_id: int}
     */
    private function createSuggestionFixture(): array
    {
        $clientId = $this->createClient(stage: ClientStage::Chatting->value);
        $now = now();
        $messageId = DB::table('conversation_messages')->insertGetId([
            'client_id' => $clientId,
            'telegram_user_id' => 123456,
            'sender' => ConversationSender::Client->value,
            'message_type' => ConversationMessageType::ClientMessage->value,
            'body' => 'Can you do this by Friday?',
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $suggestionId = DB::table('bot_suggestions')->insertGetId([
            'client_id' => $clientId,
            'conversation_message_id' => $messageId,
            'telegram_user_id' => 123456,
            'client_read' => 'Client asks about timeline.',
            'best_move' => 'Clarify scope.',
            'risk_level' => 'medium',
            'risk_reason' => 'Timeline pressure.',
            'detected_intent' => 'timeline_check',
            'next_stage' => ClientStage::Chatting->value,
            'status' => BotSuggestionStatus::Generated->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'client_id' => $clientId,
            'suggestion_id' => $suggestionId,
            'option_1_id' => $this->createOption($suggestionId, 1, 'Short reply text.'),
            'option_2_id' => $this->createOption($suggestionId, 2, 'Professional reply text.'),
            'option_3_id' => $this->createOption($suggestionId, 3, 'Closing reply text.'),
        ];
    }

    private function createClient(string $stage): int
    {
        $now = now();

        DB::table('telegram_users')->insertOrIgnore([
            'telegram_user_id' => 123456,
            'chat_id' => 123456,
            'username' => 'test_user',
            'first_name' => 'Test',
            'is_allowed' => true,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $clientId = DB::table('clients')->insertGetId([
            'telegram_user_id' => 123456,
            'title' => 'Laravel Telegram CRM',
            'status' => ClientStatus::Active->value,
            'stage' => $stage,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('telegram_user_states')->updateOrInsert(
            ['telegram_user_id' => 123456],
            [
                'state' => $stage === ClientStage::Chatting->value ? ClientSessionManager::STATE_CHATTING_WITH_CLIENT : null,
                'payload' => '{}',
                'active_client_id' => $clientId,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $clientId;
    }

    private function createOption(int $suggestionId, int $optionNumber, string $body): int
    {
        $labels = [
            1 => 'Short',
            2 => 'Professional',
            3 => 'Closing / Sales-focused',
        ];

        return DB::table('bot_suggestion_options')->insertGetId([
            'bot_suggestion_id' => $suggestionId,
            'option_number' => $optionNumber,
            'label' => $labels[$optionNumber],
            'body' => $body,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertCallbackAnswered(string $callbackQueryId): void
    {
        Http::assertSent(function ($request) use ($callbackQueryId): bool {
            return str_contains($request->url(), '/answerCallbackQuery')
                && data_get($request->data(), 'callback_query_id') === $callbackQueryId;
        });
    }

    private function sendTelegramText(int $updateId, string $text): TestResponse
    {
        return $this->postJson('/api/telegram/webhook/test-secret', [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => [
                    'id' => 123456,
                    'is_bot' => false,
                    'first_name' => 'Test',
                    'username' => 'test_user',
                ],
                'chat' => [
                    'id' => 123456,
                    'type' => 'private',
                ],
                'date' => 1782650000,
                'text' => $text,
            ],
        ]);
    }

    private function sendTelegramCallback(int $updateId, string $callbackData, int $telegramUserId = 123456): TestResponse
    {
        return $this->postJson('/api/telegram/webhook/test-secret', [
            'update_id' => $updateId,
            'callback_query' => [
                'id' => 'cb_'.$updateId,
                'from' => [
                    'id' => $telegramUserId,
                    'is_bot' => false,
                    'first_name' => 'Test',
                    'username' => 'test_user',
                ],
                'message' => [
                    'message_id' => $updateId,
                    'chat' => [
                        'id' => $telegramUserId,
                        'type' => 'private',
                    ],
                ],
                'data' => $callbackData,
            ],
        ]);
    }
}
