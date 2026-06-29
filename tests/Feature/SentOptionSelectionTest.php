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

class SentOptionSelectionTest extends TestCase
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

    public function test_sent_option_marks_suggestion_and_stores_selected_reply_message(): void
    {
        $fixture = $this->createSuggestionFixture();

        $this->sendTelegramText(1, TelegramMessageFormatter::BUTTON_SENT_OPTION_2)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('bot_suggestions', [
            'id' => $fixture['suggestion_id'],
            'status' => BotSuggestionStatus::Selected->value,
            'selected_option_id' => $fixture['option_2_id'],
            'selected_text' => 'Professional reply text.',
        ]);

        $message = DB::table('conversation_messages')
            ->where('client_id', $fixture['client_id'])
            ->where('message_type', ConversationMessageType::SelectedReply->value)
            ->first();

        $this->assertNotNull($message);
        $this->assertSame(ConversationSender::Mehrdad->value, $message->sender);
        $this->assertSame('Professional reply text.', $message->body);
        $this->assertSame([
            'suggestion_id' => $fixture['suggestion_id'],
            'option_id' => $fixture['option_2_id'],
        ], json_decode($message->metadata, true));

        $this->assertDatabaseHas('telegram_user_states', [
            'telegram_user_id' => 123456,
            'state' => ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
            'active_client_id' => $fixture['client_id'],
        ]);

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->sentReplySavedMessage();
        });
    }

    public function test_already_selected_suggestion_does_not_create_duplicate_message(): void
    {
        $fixture = $this->createSuggestionFixture();

        $this->sendTelegramText(10, TelegramMessageFormatter::BUTTON_SENT_OPTION_1)->assertOk();
        $this->sendTelegramText(11, TelegramMessageFormatter::BUTTON_SENT_OPTION_3)->assertOk();

        $this->assertSame(1, DB::table('conversation_messages')
            ->where('client_id', $fixture['client_id'])
            ->where('message_type', ConversationMessageType::SelectedReply->value)
            ->count());

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->alreadySelectedReplyMessage();
        });
    }

    private function createSuggestionFixture(): array
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
            'stage' => ClientStage::Chatting->value,
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
            'body' => 'Can you do this by Friday?',
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

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

        $option1Id = $this->createOption($suggestionId, 1, 'Short', 'Short reply text.');
        $option2Id = $this->createOption($suggestionId, 2, 'Professional', 'Professional reply text.');
        $option3Id = $this->createOption($suggestionId, 3, 'Closing / Sales-focused', 'Closing reply text.');

        return [
            'client_id' => $clientId,
            'suggestion_id' => $suggestionId,
            'option_1_id' => $option1Id,
            'option_2_id' => $option2Id,
            'option_3_id' => $option3Id,
        ];
    }

    private function createOption(int $suggestionId, int $optionNumber, string $label, string $body): int
    {
        return DB::table('bot_suggestion_options')->insertGetId([
            'bot_suggestion_id' => $suggestionId,
            'option_number' => $optionNumber,
            'label' => $label,
            'body' => $body,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
