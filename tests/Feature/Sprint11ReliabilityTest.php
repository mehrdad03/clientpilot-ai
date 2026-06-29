<?php

namespace Tests\Feature;

use App\Contracts\Ai\AiProviderInterface;
use App\Enums\BotSuggestionStatus;
use App\Enums\ClientStage;
use App\Enums\ClientStatus;
use App\Enums\ConversationMessageType;
use App\Enums\ConversationSender;
use App\Jobs\AnalyzeClientJob;
use App\Services\Clients\ClientSessionManager;
use App\Services\Telegram\TelegramMessageFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class Sprint11ReliabilityTest extends TestCase
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
    }

    public function test_start_command_sends_main_menu_and_duplicate_update_is_ignored(): void
    {
        $this->sendTelegramText(1, '/start')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->sendTelegramText(1, '/start')
            ->assertOk()
            ->assertJson(['ok' => true, 'duplicate' => true]);

        $this->assertDatabaseCount('telegram_updates', 1);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->startMessage()
                && data_get($request->data(), 'reply_markup.keyboard.0.0.text') === TelegramMessageFormatter::BUTTON_NEW_CLIENT;
        });
    }

    public function test_unauthorized_telegram_user_gets_access_denied(): void
    {
        $this->sendTelegramText(2, '/start', 999999)
            ->assertOk()
            ->assertJson(['ok' => true, 'unauthorized' => true]);

        $this->assertDatabaseHas('telegram_users', [
            'telegram_user_id' => 999999,
            'is_allowed' => false,
        ]);

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->accessDeniedMessage();
        });
    }

    public function test_new_client_flow_creates_client_and_initial_message(): void
    {
        Queue::fake();

        $this->sendTelegramText(3, TelegramMessageFormatter::BUTTON_NEW_CLIENT)->assertOk();
        $this->sendTelegramText(4, 'Need a Laravel dashboard for client marketplace leads.')->assertOk();

        $client = DB::table('clients')->where('telegram_user_id', 123456)->first();

        $this->assertNotNull($client);
        $this->assertSame(ClientStage::Intake->value, $client->stage);

        $this->assertDatabaseHas('conversation_messages', [
            'client_id' => $client->id,
            'sender' => ConversationSender::Client->value,
            'message_type' => ConversationMessageType::InitialJob->value,
            'body' => 'Need a Laravel dashboard for client marketplace leads.',
        ]);

        $this->assertDatabaseHas('telegram_user_states', [
            'telegram_user_id' => 123456,
            'active_client_id' => $client->id,
        ]);

        Queue::assertPushed(AnalyzeClientJob::class);
    }

    public function test_my_clients_lists_active_client(): void
    {
        $clientId = $this->createChattingClient(title: 'Laravel Telegram CRM');

        $this->sendTelegramText(5, TelegramMessageFormatter::BUTTON_MY_CLIENTS)->assertOk();

        Http::assertSent(function ($request) use ($clientId): bool {
            $text = (string) data_get($request->data(), 'text');

            return str_contains($text, "#{$clientId} Laravel Telegram CRM");
        });
    }

    public function test_select_sent_option_is_retry_safe(): void
    {
        Queue::fake();

        $fixture = $this->createSuggestionFixture();

        $this->sendTelegramText(6, TelegramMessageFormatter::BUTTON_SENT_OPTION_1)->assertOk();
        $this->sendTelegramText(7, TelegramMessageFormatter::BUTTON_SENT_OPTION_1)->assertOk();

        $this->assertDatabaseHas('bot_suggestions', [
            'id' => $fixture['suggestion_id'],
            'status' => BotSuggestionStatus::Selected->value,
            'selected_option_id' => $fixture['option_id'],
            'selected_text' => 'I can help after we confirm the scope.',
        ]);

        $this->assertSame(1, DB::table('conversation_messages')
            ->where('client_id', $fixture['client_id'])
            ->where('message_type', ConversationMessageType::SelectedReply->value)
            ->count());
    }

    public function test_feedback_button_sets_waiting_state_without_running_ai(): void
    {
        $fixture = $this->createSuggestionFixture();

        $this->sendTelegramText(8, TelegramMessageFormatter::BUTTON_DISLIKE_REPLIES)->assertOk();

        $state = DB::table('telegram_user_states')->where('telegram_user_id', 123456)->first();
        $payload = json_decode($state->payload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(ClientSessionManager::STATE_WAITING_FOR_FEEDBACK_REASON, $state->state);
        $this->assertSame($fixture['suggestion_id'], $payload['bot_suggestion_id']);
    }

    public function test_custom_reply_button_sets_waiting_state_without_changing_selected_flow(): void
    {
        $fixture = $this->createSuggestionFixture();

        $this->sendTelegramText(9, TelegramMessageFormatter::BUTTON_OWN_REPLY)->assertOk();

        $state = DB::table('telegram_user_states')->where('telegram_user_id', 123456)->first();
        $payload = json_decode($state->payload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(ClientSessionManager::STATE_WAITING_FOR_CUSTOM_REPLY, $state->state);
        $this->assertSame($fixture['client_id'], $payload['client_id']);
        $this->assertSame($fixture['suggestion_id'], $payload['suggestion_id']);
    }

    public function test_risk_guard_high_risk_reply_stays_freelancehub_safe(): void
    {
        $clientId = $this->createChattingClient();
        $this->bindReplyAndSummaryProvider();

        $this->sendTelegramText(10, 'Can we use WhatsApp and PayPal? Please start before the milestone is funded.')
            ->assertOk();

        $suggestion = DB::table('bot_suggestions')->where('client_id', $clientId)->first();
        $optionBodies = strtolower(DB::table('bot_suggestion_options')
            ->where('bot_suggestion_id', $suggestion->id)
            ->pluck('body')
            ->implode("\n"));

        $this->assertSame('high', $suggestion->risk_level);
        $this->assertStringNotContainsString('whatsapp', $optionBodies);
        $this->assertStringNotContainsString('paypal', $optionBodies);
        $this->assertStringContainsString('freelancehub', $optionBodies);
        $this->assertStringContainsString('funded', $optionBodies);

        Http::assertSent(function ($request): bool {
            return str_contains((string) data_get($request->data(), 'text'), 'هشدار ریسک بالا');
        });
    }

    public function test_view_summary_uses_safe_fallback_when_missing(): void
    {
        $this->createChattingClient();

        $this->sendTelegramText(11, TelegramMessageFormatter::BUTTON_VIEW_SUMMARY)->assertOk();

        Http::assertSent(function ($request): bool {
            $text = (string) data_get($request->data(), 'text');

            return str_contains($text, 'خلاصه')
                && str_contains($text, 'وجود ندارد');
        });
    }

    public function test_busy_client_ai_lock_sends_safe_message_without_creating_suggestion(): void
    {
        $clientId = $this->createChattingClient();
        $this->bindReplyAndSummaryProvider();
        $lock = Cache::lock('client-ai-processing:'.$clientId, 180);
        $this->assertTrue($lock->get());

        try {
            $this->sendTelegramText(12, 'Can you review this message?')->assertOk();

            $this->assertSame(0, DB::table('bot_suggestions')->where('client_id', $clientId)->count());

            Http::assertSent(function ($request): bool {
                return str_contains((string) data_get($request->data(), 'text'), 'تحلیل قبلی هنوز در حال انجام است');
            });
        } finally {
            $lock->release();
        }
    }

    public function test_failed_ai_job_sends_safe_message_and_keeps_client_message(): void
    {
        $clientId = $this->createChattingClient();

        $this->app->instance(AiProviderInterface::class, new class implements AiProviderInterface
        {
            public function createResponse(array|string $input, array $options = []): array
            {
                throw new RuntimeException('Provider failed with token=FAKE_OPENAI_KEY_FOR_MASKING_TEST');
            }
        });

        $this->sendTelegramText(13, 'Can you estimate this?')->assertOk();

        $this->assertDatabaseHas('conversation_messages', [
            'client_id' => $clientId,
            'sender' => ConversationSender::Client->value,
            'message_type' => ConversationMessageType::ClientMessage->value,
            'body' => 'Can you estimate this?',
        ]);

        Http::assertSent(function ($request): bool {
            return str_contains((string) data_get($request->data(), 'text'), 'در پردازش هوش مصنوعی خطایی رخ داد');
        });
    }

    private function bindReplyAndSummaryProvider(): void
    {
        $this->app->instance(AiProviderInterface::class, new class implements AiProviderInterface
        {
            public function createResponse(array|string $input, array $options = []): array
            {
                $prompt = is_string($input) ? $input : json_encode($input, JSON_THROW_ON_ERROR);

                if (str_contains($prompt, '# sales_copilot_summary_v1')) {
                    return [
                        'output_text' => json_encode([
                            'summary' => 'Client is discussing scope.',
                            'current_context' => 'Waiting for Mehrdad.',
                            'what_client_wants' => 'A Laravel workflow.',
                            'what_mehrdad_promised' => 'Nothing firm yet.',
                            'pricing_discussed' => 'None known yet.',
                            'deadline_discussed' => 'None known yet.',
                            'access_needed' => 'None known yet.',
                            'open_questions' => 'Scope.',
                            'risk_notes' => 'Outside payment/contact risk may exist.',
                            'next_best_move' => 'Keep everything on FreelanceHub.',
                        ], JSON_THROW_ON_ERROR),
                    ];
                }

                return [
                    'output_text' => json_encode([
                        'client_read' => 'Client is pushing an unsafe path.',
                        'best_move' => 'Move to WhatsApp and PayPal.',
                        'risk_level' => 'low',
                        'risk_reason' => 'No risk.',
                        'detected_intent' => 'unsafe_contact_payment',
                        'next_stage' => ClientStage::Chatting->value,
                        'reply_options' => [
                            [
                                'label' => 'Short',
                                'body' => 'Sure, WhatsApp works and PayPal is fine.',
                            ],
                            [
                                'label' => 'Professional',
                                'body' => 'Email me and I can start before the milestone.',
                            ],
                            [
                                'label' => 'Closing / Sales-focused',
                                'body' => 'Send direct payment and I will start today.',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];
            }
        });
    }

    /**
     * @return array{client_id: int, suggestion_id: int, option_id: int}
     */
    private function createSuggestionFixture(): array
    {
        $clientId = $this->createChattingClient();
        $now = now();
        $messageId = DB::table('conversation_messages')->insertGetId([
            'client_id' => $clientId,
            'telegram_user_id' => 123456,
            'sender' => ConversationSender::Client->value,
            'message_type' => ConversationMessageType::ClientMessage->value,
            'body' => 'Can you finish by Friday?',
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
        $optionId = DB::table('bot_suggestion_options')->insertGetId([
            'bot_suggestion_id' => $suggestionId,
            'option_number' => 1,
            'label' => 'Short',
            'body' => 'I can help after we confirm the scope.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'client_id' => $clientId,
            'suggestion_id' => $suggestionId,
            'option_id' => $optionId,
        ];
    }

    private function createChattingClient(string $title = 'Laravel CRM'): int
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
            'title' => $title,
            'status' => ClientStatus::Active->value,
            'stage' => ClientStage::Chatting->value,
            'client_type' => 'Startup founder',
            'personality_type' => 'Direct',
            'main_need' => 'Build a Laravel workflow',
            'best_strategy' => 'Keep scope and milestones clear.',
            'risk_level' => 'medium',
            'client_summary' => 'Client needs Laravel help.',
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

        DB::table('conversation_messages')->insert([
            'client_id' => $clientId,
            'telegram_user_id' => 123456,
            'sender' => ConversationSender::Client->value,
            'message_type' => ConversationMessageType::InitialJob->value,
            'body' => 'Need a Laravel workflow.',
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $clientId;
    }

    private function sendTelegramText(int $updateId, string $text, int $telegramUserId = 123456): TestResponse
    {
        return $this->postJson('/api/telegram/webhook/test-secret', [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => [
                    'id' => $telegramUserId,
                    'is_bot' => false,
                    'first_name' => 'Test',
                    'username' => 'test_user',
                ],
                'chat' => [
                    'id' => $telegramUserId,
                    'type' => 'private',
                ],
                'date' => 1782650000,
                'text' => $text,
            ],
        ]);
    }
}
