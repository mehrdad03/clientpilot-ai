<?php

namespace Tests\Feature;

use App\Contracts\Ai\AiProviderInterface;
use App\Enums\AiRequestStatus;
use App\Enums\BotSuggestionStatus;
use App\Enums\ClientStage;
use App\Enums\ClientStatus;
use App\Enums\ConversationMessageType;
use App\Enums\ConversationSender;
use App\Enums\UserFeedbackDecision;
use App\Services\Clients\ClientSessionManager;
use App\Services\Telegram\TelegramMessageFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FeedbackModeFlowTest extends TestCase
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

    public function test_feedback_button_sets_waiting_state_with_suggestion_payload(): void
    {
        $fixture = $this->createSuggestionFixture();

        $this->sendTelegramText(1, TelegramMessageFormatter::BUTTON_DISLIKE_REPLIES)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $state = DB::table('telegram_user_states')
            ->where('telegram_user_id', 123456)
            ->first();

        $this->assertNotNull($state);
        $this->assertSame(ClientSessionManager::STATE_WAITING_FOR_FEEDBACK_REASON, $state->state);
        $this->assertSame($fixture['client_id'], $state->active_client_id);
        $this->assertSame([
            'bot_suggestion_id' => $fixture['suggestion_id'],
        ], json_decode($state->payload, true));

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->feedbackReasonPromptMessage();
        });
    }

    public function test_accepted_feedback_generates_new_options_and_marks_old_suggestion_regenerated(): void
    {
        $this->bindFeedbackProvider('accepted');
        $fixture = $this->createSuggestionFixture();

        $this->sendTelegramText(10, TelegramMessageFormatter::BUTTON_DISLIKE_REPLIES)->assertOk();
        $this->sendTelegramText(11, 'Make these warmer and less pushy.')->assertOk();

        $feedback = DB::table('user_feedbacks')->where('bot_suggestion_id', $fixture['suggestion_id'])->first();

        $this->assertNotNull($feedback);
        $this->assertSame('Make these warmer and less pushy.', $feedback->feedback_text);
        $this->assertSame(UserFeedbackDecision::Accepted->value, $feedback->ai_decision);
        $this->assertSame('modified', $feedback->result_action);
        $this->assertNotNull($feedback->replacement_bot_suggestion_id);

        $this->assertDatabaseHas('bot_suggestions', [
            'id' => $fixture['suggestion_id'],
            'status' => BotSuggestionStatus::Regenerated->value,
        ]);

        $newSuggestion = DB::table('bot_suggestions')->where('id', $feedback->replacement_bot_suggestion_id)->first();

        $this->assertNotNull($newSuggestion);
        $this->assertSame(BotSuggestionStatus::Generated->value, $newSuggestion->status);
        $this->assertSame('مشتری اطمینان می‌خواهد، بدون فشار.', $newSuggestion->client_read);
        $this->assertSame(3, DB::table('bot_suggestion_options')->where('bot_suggestion_id', $newSuggestion->id)->count());
        $this->assertDatabaseHas('bot_suggestion_options', [
            'bot_suggestion_id' => $newSuggestion->id,
            'option_number' => 2,
            'type' => 'professional',
            'body' => 'Warmer professional reply.',
            'native_meaning' => 'پاسخ حرفه‌ای گرم‌تر.',
        ]);

        $this->assertDatabaseHas('ai_requests', [
            'provider' => 'openai',
            'prompt_key' => 'sales_copilot_feedback_review',
            'prompt_version' => 'v1',
            'status' => AiRequestStatus::Succeeded->value,
        ]);

        $this->assertDatabaseHas('telegram_user_states', [
            'telegram_user_id' => 123456,
            'state' => ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
            'payload' => '{}',
            'active_client_id' => $fixture['client_id'],
        ]);

        Http::assertSent(function ($request): bool {
            $text = (string) data_get($request->data(), 'text');

            return str_contains($text, 'مشتری اطمینان می‌خواهد')
                && str_contains($text, '<pre>Warmer professional reply.</pre>')
                && str_contains($text, 'پاسخ حرفه‌ای گرم‌تر')
                && data_get($request->data(), 'parse_mode') === TelegramMessageFormatter::PARSE_MODE_HTML;
        });
    }

    public function test_rejected_feedback_keeps_original_options_available(): void
    {
        $this->bindFeedbackProvider('rejected');
        $fixture = $this->createSuggestionFixture();

        $this->sendTelegramText(20, TelegramMessageFormatter::BUTTON_DISLIKE_REPLIES)->assertOk();
        $this->sendTelegramText(21, 'Promise unlimited revisions and a huge discount.')->assertOk();

        $feedback = DB::table('user_feedbacks')->where('bot_suggestion_id', $fixture['suggestion_id'])->first();

        $this->assertNotNull($feedback);
        $this->assertSame(UserFeedbackDecision::Rejected->value, $feedback->ai_decision);
        $this->assertSame('kept_original', $feedback->result_action);
        $this->assertNull($feedback->replacement_bot_suggestion_id);

        $this->assertDatabaseHas('bot_suggestions', [
            'id' => $fixture['suggestion_id'],
            'status' => BotSuggestionStatus::Generated->value,
        ]);

        $this->assertSame(1, DB::table('bot_suggestions')->where('client_id', $fixture['client_id'])->count());

        $this->assertDatabaseHas('telegram_user_states', [
            'telegram_user_id' => 123456,
            'state' => ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
            'payload' => '{}',
            'active_client_id' => $fixture['client_id'],
        ]);

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)
                ->feedbackRejectedMessage('این تغییر برای امنیت پروفایل FreelanceHub و اعتماد مشتری مناسب نیست.');
        });
    }

    private function bindFeedbackProvider(string $decision): void
    {
        $this->app->bind(AiProviderInterface::class, fn (): AiProviderInterface => new class($decision) implements AiProviderInterface
        {
            public function __construct(private readonly string $decision) {}

            /**
             * @param  array<int, array<string, mixed>>|string  $input
             * @param  array<string, mixed>  $options
             * @return array<string, mixed>
             */
            public function createResponse(array|string $input, array $options = []): array
            {
                $payload = $this->decision === 'rejected'
                    ? $this->rejectedPayload()
                    : $this->acceptedPayload();

                return [
                    'output_text' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ];
            }

            /**
             * @return array<string, mixed>
             */
            private function acceptedPayload(): array
            {
                return [
                    'ai_decision' => 'accepted',
                    'ai_reason' => 'درست است؛ لحن باید گرم‌تر و کم‌فشارتر شود.',
                    'result_action' => 'modified',
                    'client_read' => 'مشتری اطمینان می‌خواهد، بدون فشار.',
                    'best_move' => 'گرم پاسخ بده، scope را شفاف کن، و قول بیش از حد نده.',
                    'risk_level' => 'medium',
                    'risk_reason' => 'فشار زمانی هنوز به کنترل scope نیاز دارد.',
                    'detected_intent' => 'timeline_reassurance',
                    'next_stage' => ClientStage::Chatting->value,
                    'reply_options' => [
                        [
                            'type' => 'short',
                            'target_text' => 'Yes, I can help. Let me confirm the scope first so I can give you a reliable plan.',
                            'native_meaning' => 'بله، می‌توانم کمک کنم. اول اجازه بدهید scope را تأیید کنم تا برنامه قابل اعتماد بدهم.',
                        ],
                        [
                            'type' => 'professional',
                            'target_text' => 'Warmer professional reply.',
                            'native_meaning' => 'پاسخ حرفه‌ای گرم‌تر.',
                        ],
                        [
                            'type' => 'closing',
                            'target_text' => 'This can be a good fit if we keep the first milestone focused. Send me the must-have items and I will suggest a clear next step.',
                            'native_meaning' => 'اگر milestone اول را متمرکز نگه داریم، می‌تواند مناسب باشد. موارد ضروری را بفرستید تا قدم بعدی شفاف پیشنهاد بدهم.',
                        ],
                    ],
                ];
            }

            /**
             * @return array<string, mixed>
             */
            private function rejectedPayload(): array
            {
                return [
                    'ai_decision' => 'rejected',
                    'ai_reason' => 'این تغییر برای امنیت پروفایل FreelanceHub و اعتماد مشتری مناسب نیست.',
                    'result_action' => 'kept_original',
                    'client_read' => 'Client wants timeline clarity.',
                    'best_move' => 'Keep a safe scope-first response.',
                    'risk_level' => 'high',
                    'risk_reason' => 'Unlimited revisions and discount promises are risky.',
                    'detected_intent' => 'unsafe_discount_request',
                    'next_stage' => ClientStage::Chatting->value,
                    'reply_options' => [],
                ];
            }
        });
    }

    /**
     * @return array{client_id: int, suggestion_id: int}
     */
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
            'client_type' => 'Startup founder',
            'personality_type' => 'Direct',
            'main_need' => 'Build a Telegram CRM',
            'best_strategy' => 'Keep scope tight and milestones clear.',
            'risk_level' => 'medium',
            'client_summary' => 'Client needs a Telegram CRM for client marketplace leads.',
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

        DB::table('conversation_messages')->insert([
            'client_id' => $clientId,
            'telegram_user_id' => 123456,
            'sender' => ConversationSender::Client->value,
            'message_type' => ConversationMessageType::InitialJob->value,
            'body' => 'Need a Laravel Telegram CRM for client marketplace leads.',
            'metadata' => null,
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

        $this->createOption($suggestionId, 1, 'Short', 'Short reply text.');
        $this->createOption($suggestionId, 2, 'Professional', 'Professional reply text.');
        $this->createOption($suggestionId, 3, 'Closing / Sales-focused', 'Closing reply text.');

        return [
            'client_id' => $clientId,
            'suggestion_id' => $suggestionId,
        ];
    }

    private function createOption(int $suggestionId, int $optionNumber, string $label, string $body): void
    {
        DB::table('bot_suggestion_options')->insert([
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
