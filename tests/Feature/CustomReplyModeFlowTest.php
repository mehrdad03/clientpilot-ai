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

class CustomReplyModeFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.bot_token' => 'test-token',
            'telegram.webhook_secret' => 'test-secret',
            'telegram.allowed_user_ids' => ['123456'],
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
    }

    public function test_custom_reply_button_sets_waiting_state_with_client_and_suggestion_payload(): void
    {
        $fixture = $this->createClientFixture(withSuggestion: true);

        $this->sendTelegramText(1, TelegramMessageFormatter::BUTTON_OWN_REPLY)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $state = DB::table('telegram_user_states')
            ->where('telegram_user_id', 123456)
            ->first();

        $this->assertNotNull($state);
        $this->assertSame(ClientSessionManager::STATE_WAITING_FOR_CUSTOM_REPLY, $state->state);
        $this->assertSame($fixture['client_id'], $state->active_client_id);
        $this->assertSame([
            'client_id' => $fixture['client_id'],
            'suggestion_id' => $fixture['suggestion_id'],
        ], json_decode($state->payload, true));

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->customReplyPromptMessage();
        });
    }

    public function test_waiting_custom_reply_stores_exact_text_and_warns_about_detected_risk(): void
    {
        $fixture = $this->createClientFixture(withSuggestion: true, stage: ClientStage::Analyzed->value);

        $this->sendTelegramText(10, TelegramMessageFormatter::BUTTON_OWN_REPLY)->assertOk();

        $exactReply = "  I can deliver this by Friday for $500 if you send admin access.\nI will make sure it is done.  ";

        $this->sendTelegramText(11, $exactReply)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $message = DB::table('conversation_messages')
            ->where('client_id', $fixture['client_id'])
            ->where('message_type', ConversationMessageType::CustomReply->value)
            ->first();

        $this->assertNotNull($message);
        $this->assertSame(ConversationSender::Mehrdad->value, $message->sender);
        $this->assertSame($exactReply, $message->body);
        $this->assertSame([
            'suggestion_id' => $fixture['suggestion_id'],
        ], json_decode($message->metadata, true));

        $this->assertDatabaseHas('clients', [
            'id' => $fixture['client_id'],
            'stage' => ClientStage::Chatting->value,
        ]);

        $this->assertDatabaseHas('telegram_user_states', [
            'telegram_user_id' => 123456,
            'state' => ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
            'payload' => '{}',
            'active_client_id' => $fixture['client_id'],
        ]);

        Http::assertSent(function ($request): bool {
            $text = (string) data_get($request->data(), 'text');

            return str_contains($text, app(\App\Services\Copilot\CopilotMessageService::class)->text('custom_reply_saved'))
                && str_contains($text, app(TelegramMessageFormatter::class)->startChatPromptMessage());
        });
    }

    public function test_custom_reply_without_suggestion_omits_suggestion_metadata(): void
    {
        $fixture = $this->createClientFixture(withSuggestion: false);

        $this->sendTelegramText(20, TelegramMessageFormatter::BUTTON_OWN_REPLY)->assertOk();
        $this->sendTelegramText(21, 'Thanks, please send me the details and I will review them.')->assertOk();

        $state = DB::table('telegram_user_states')
            ->where('telegram_user_id', 123456)
            ->first();

        $this->assertSame(ClientSessionManager::STATE_CHATTING_WITH_CLIENT, $state->state);

        $message = DB::table('conversation_messages')
            ->where('client_id', $fixture['client_id'])
            ->where('message_type', ConversationMessageType::CustomReply->value)
            ->first();

        $this->assertNotNull($message);
        $this->assertNull($message->metadata);

        $this->assertDatabaseMissing('ai_requests', [
            'prompt_key' => 'sales_copilot_reply',
        ]);
    }

    /**
     * @return array{client_id: int, suggestion_id: int|null}
     */
    private function createClientFixture(bool $withSuggestion, string $stage = ClientStage::Chatting->value): array
    {
        $now = now();

        DB::table('telegram_users')->insert([
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

        DB::table('telegram_user_states')->insert([
            'telegram_user_id' => 123456,
            'state' => ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
            'payload' => '{}',
            'active_client_id' => $clientId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $clientMessageId = DB::table('conversation_messages')->insertGetId([
            'client_id' => $clientId,
            'telegram_user_id' => 123456,
            'sender' => ConversationSender::Client->value,
            'message_type' => ConversationMessageType::ClientMessage->value,
            'body' => 'Can you finish this by Friday?',
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $suggestionId = null;

        if ($withSuggestion) {
            $suggestionId = DB::table('bot_suggestions')->insertGetId([
                'client_id' => $clientId,
                'conversation_message_id' => $clientMessageId,
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
        }

        return [
            'client_id' => $clientId,
            'suggestion_id' => $suggestionId,
        ];
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
}
