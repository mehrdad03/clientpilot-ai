<?php

namespace Tests\Feature;

use App\Contracts\Ai\AiProviderInterface;
use App\Enums\BotSuggestionStatus;
use App\Enums\ClientStage;
use App\Enums\ClientStatus;
use App\Enums\ConversationMessageType;
use App\Enums\ConversationSender;
use App\Jobs\UpdateClientSummaryJob;
use App\Services\Clients\ClientSessionManager;
use App\Services\Clients\ConversationBrainService;
use App\Services\Telegram\TelegramMessageFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MemorySummaryFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.bot_token' => 'test-token',
            'telegram.webhook_secret' => 'test-secret',
            'telegram.allowed_user_ids' => ['123456'],
            'queue.default' => 'sync',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->bindSummaryProvider();
    }

    public function test_update_client_summary_job_stores_summary_and_last_message_id(): void
    {
        $fixture = $this->createClientFixture();
        $lastMessageId = $this->addMessage($fixture['client_id'], ConversationSender::Mehrdad->value, ConversationMessageType::CustomReply->value, 'I can review this by Friday.');

        UpdateClientSummaryJob::dispatchSync($fixture['client_id']);

        $this->assertDatabaseHas('client_summaries', [
            'client_id' => $fixture['client_id'],
            'telegram_user_id' => 123456,
            'summary' => 'Client wants a Laravel Telegram CRM and is discussing timeline.',
            'current_context' => 'Waiting for the next client message.',
            'last_message_id' => $lastMessageId,
        ]);

        $this->assertDatabaseHas('ai_requests', [
            'provider' => 'openai',
            'prompt_key' => 'sales_copilot_summary',
            'prompt_version' => 'v1',
        ]);
    }

    public function test_view_summary_returns_safe_message_when_summary_is_missing(): void
    {
        $this->createClientFixture();

        $this->sendTelegramText(1, TelegramMessageFormatter::BUTTON_VIEW_SUMMARY)
            ->assertOk()
            ->assertJson(['ok' => true]);

        Http::assertSent(function ($request): bool {
            $text = (string) data_get($request->data(), 'text');

            return str_contains($text, 'خلاصه')
                && str_contains($text, 'وجود ندارد');
        });
    }

    public function test_view_summary_displays_existing_memory_summary(): void
    {
        $fixture = $this->createClientFixture();
        $lastMessageId = $this->addMessage($fixture['client_id'], ConversationSender::Client->value, ConversationMessageType::ClientMessage->value, 'Can you finish by Friday?');

        DB::table('client_summaries')->insert([
            'client_id' => $fixture['client_id'],
            'telegram_user_id' => 123456,
            'summary' => 'Client needs a scoped Laravel CRM.',
            'current_context' => 'Discussing timeline.',
            'what_client_wants' => 'A Telegram CRM.',
            'what_mehrdad_promised' => 'Mehrdad promised to review scope.',
            'pricing_discussed' => 'None known yet.',
            'deadline_discussed' => 'Friday was mentioned.',
            'access_needed' => 'None known yet.',
            'open_questions' => 'Exact scope is still open.',
            'risk_notes' => 'Timeline could be tight.',
            'next_best_move' => 'Ask for must-have features.',
            'last_message_id' => $lastMessageId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->sendTelegramText(2, TelegramMessageFormatter::BUTTON_VIEW_SUMMARY)->assertOk();

        Http::assertSent(function ($request): bool {
            $text = (string) data_get($request->data(), 'text');

            return str_contains($text, 'Client needs a scoped Laravel CRM.')
                && str_contains($text, 'Friday was mentioned.');
        });
    }

    public function test_conversation_brain_uses_memory_summary_and_recent_messages(): void
    {
        $fixture = $this->createClientFixture();

        DB::table('client_summaries')->insert([
            'client_id' => $fixture['client_id'],
            'telegram_user_id' => 123456,
            'summary' => 'Persistent memory summary.',
            'current_context' => 'Current context from memory.',
            'what_client_wants' => 'Client wants CRM.',
            'what_mehrdad_promised' => 'None known yet.',
            'pricing_discussed' => 'None known yet.',
            'deadline_discussed' => 'None known yet.',
            'access_needed' => 'None known yet.',
            'open_questions' => 'Scope.',
            'risk_notes' => 'Low.',
            'next_best_move' => 'Ask a clear question.',
            'last_message_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        for ($i = 1; $i <= 15; $i++) {
            $messageType = $i === 15 ? ConversationMessageType::ClientMessage->value : ConversationMessageType::CustomReply->value;
            $latestMessageId = $this->addMessage($fixture['client_id'], ConversationSender::Client->value, $messageType, sprintf('message-%02d', $i));
        }

        $brain = app(ConversationBrainService::class);
        $context = $brain->contextForReplySuggestions($fixture['client_id'], $latestMessageId);
        $formatted = $brain->formatConversation($context['conversation'], $context['summary']);

        $this->assertStringContainsString('Persistent memory summary.', $formatted);
        $this->assertStringContainsString('message-15', $formatted);
        $this->assertStringNotContainsString('message-01', $formatted);
        $this->assertStringNotContainsString('message-02', $formatted);
    }

    public function test_selected_reply_triggers_summary_update(): void
    {
        $fixture = $this->createClientFixture(withSuggestion: true);

        $this->sendTelegramText(30, TelegramMessageFormatter::BUTTON_SENT_OPTION_1)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $selectedMessage = DB::table('conversation_messages')
            ->where('client_id', $fixture['client_id'])
            ->where('message_type', ConversationMessageType::SelectedReply->value)
            ->first();

        $this->assertNotNull($selectedMessage);

        $this->assertDatabaseHas('client_summaries', [
            'client_id' => $fixture['client_id'],
            'summary' => 'Client wants a Laravel Telegram CRM and is discussing timeline.',
            'last_message_id' => $selectedMessage->id,
        ]);
    }

    private function bindSummaryProvider(): void
    {
        $this->app->bind(AiProviderInterface::class, fn (): AiProviderInterface => new class implements AiProviderInterface
        {
            /**
             * @param  array<int, array<string, mixed>>|string  $input
             * @param  array<string, mixed>  $options
             * @return array<string, mixed>
             */
            public function createResponse(array|string $input, array $options = []): array
            {
                return [
                    'output_text' => json_encode([
                        'summary' => 'Client wants a Laravel Telegram CRM and is discussing timeline.',
                        'current_context' => 'Waiting for the next client message.',
                        'what_client_wants' => 'A reliable Telegram CRM workflow.',
                        'what_mehrdad_promised' => 'Mehrdad promised to review scope before committing.',
                        'pricing_discussed' => 'No price agreed yet.',
                        'deadline_discussed' => 'Friday was mentioned as a possible deadline.',
                        'access_needed' => 'Admin access may be needed later.',
                        'open_questions' => 'Exact scope and priorities.',
                        'risk_notes' => 'Timeline pressure could increase scope risk.',
                        'next_best_move' => 'Ask for must-have features and confirm scope.',
                    ], JSON_THROW_ON_ERROR),
                ];
            }
        });
    }

    /**
     * @return array{client_id: int, suggestion_id: int|null}
     */
    private function createClientFixture(bool $withSuggestion = false): array
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
            'stage' => ClientStage::Chatting->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('telegram_user_states')->updateOrInsert(
            ['telegram_user_id' => 123456],
            [
                'state' => ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
                'payload' => '{}',
                'active_client_id' => $clientId,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $clientMessageId = $this->addMessage($clientId, ConversationSender::Client->value, ConversationMessageType::ClientMessage->value, 'Can you finish this by Friday?');
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

            DB::table('bot_suggestion_options')->insert([
                'bot_suggestion_id' => $suggestionId,
                'option_number' => 1,
                'label' => 'Short',
                'body' => 'I can help, but I need to confirm the scope first.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return [
            'client_id' => $clientId,
            'suggestion_id' => $suggestionId,
        ];
    }

    private function addMessage(int $clientId, string $sender, string $messageType, string $body): int
    {
        return DB::table('conversation_messages')->insertGetId([
            'client_id' => $clientId,
            'telegram_user_id' => 123456,
            'sender' => $sender,
            'message_type' => $messageType,
            'body' => $body,
            'metadata' => null,
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
