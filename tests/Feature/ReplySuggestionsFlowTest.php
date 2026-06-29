<?php

namespace Tests\Feature;

use App\Contracts\Ai\AiProviderInterface;
use App\Enums\AiRequestStatus;
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

class ReplySuggestionsFlowTest extends TestCase
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

        $this->bindReplyProvider(nextStage: 'not-a-valid-stage');
    }

    public function test_chatting_state_stores_client_message_and_generates_reply_suggestions(): void
    {
        $clientId = $this->createChattingClient();

        $this->sendTelegramText(1, 'Can you finish this by Friday and what would it cost?')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $clientMessage = DB::table('conversation_messages')
            ->where('client_id', $clientId)
            ->where('message_type', ConversationMessageType::ClientMessage->value)
            ->first();

        $this->assertNotNull($clientMessage);
        $this->assertSame(ConversationSender::Client->value, $clientMessage->sender);
        $this->assertSame('Can you finish this by Friday and what would it cost?', $clientMessage->body);

        $suggestion = DB::table('bot_suggestions')->where('client_id', $clientId)->first();

        $this->assertNotNull($suggestion);
        $this->assertSame($clientMessage->id, $suggestion->conversation_message_id);
        $this->assertSame('مشتری درباره زمان و بودجه سؤال دارد.', $suggestion->client_read);
        $this->assertSame('medium', $suggestion->risk_level);
        $this->assertSame('not-a-valid-stage', $suggestion->next_stage);

        $this->assertSame(3, DB::table('bot_suggestion_options')->where('bot_suggestion_id', $suggestion->id)->count());
        $this->assertDatabaseHas('bot_suggestion_options', [
            'bot_suggestion_id' => $suggestion->id,
            'option_number' => 1,
            'type' => 'short',
            'body' => 'Yes, I can help. Could you confirm the must-have scope for Friday?',
            'native_meaning' => 'بله، می‌توانم کمک کنم. لطفاً scope ضروری برای جمعه را تأیید کنید؟',
        ]);

        $this->assertDatabaseHas('ai_requests', [
            'provider' => 'openai',
            'prompt_key' => 'sales_copilot_reply',
            'prompt_version' => 'v1',
            'status' => AiRequestStatus::Succeeded->value,
        ]);

        $this->assertDatabaseHas('clients', [
            'id' => $clientId,
            'stage' => ClientStage::Chatting->value,
        ]);

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === 'در حال تحلیل پیام مشتری...';
        });

        Http::assertSent(function ($request): bool {
            $text = (string) data_get($request->data(), 'text');

            return str_contains($text, 'برداشت از پیام مشتری')
                && str_contains($text, '<pre>Yes, I can help. Could you confirm the must-have scope for Friday?</pre>')
                && str_contains($text, 'معنی:')
                && data_get($request->data(), 'parse_mode') === TelegramMessageFormatter::PARSE_MODE_HTML
                && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.text') === TelegramMessageFormatter::BUTTON_SENT_OPTION_1
                && str_starts_with((string) data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data'), 'sg:sel:');

            return str_contains($text, '🧠 Client read:')
                && str_contains($text, '💬 Reply Options:')
                && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.text') === TelegramMessageFormatter::BUTTON_SENT_OPTION_1
                && str_starts_with((string) data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data'), 'sg:sel:');
        });
    }

    public function test_regenerate_without_suggestion_returns_safe_stale_message_without_persistence(): void
    {
        $this->createChattingClient();

        $this->sendTelegramText(10, TelegramMessageFormatter::BUTTON_REGENERATE)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(0, DB::table('conversation_messages')->where('message_type', ConversationMessageType::ClientMessage->value)->count());
        $this->assertSame(0, DB::table('bot_suggestions')->count());

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->staleSuggestionMessage();

            return data_get($request->data(), 'text') === 'این بخش در Sprint بعدی پیاده‌سازی می‌شود.';
        });
    }

    private function bindReplyProvider(string $nextStage): void
    {
        $this->app->bind(AiProviderInterface::class, fn (): AiProviderInterface => new class($nextStage) implements AiProviderInterface
        {
            public function __construct(private readonly string $nextStage) {}

            /**
             * @param  array<int, array<string, mixed>>|string  $input
             * @param  array<string, mixed>  $options
             * @return array<string, mixed>
             */
            public function createResponse(array|string $input, array $options = []): array
            {
                return [
                    'output_text' => json_encode([
                        'client_read' => 'مشتری درباره زمان و بودجه سؤال دارد.',
                        'best_move' => 'scope را شفاف کن، یک بازه زمانی عملی بده، و تأیید سریع بگیر.',
                        'risk_level' => 'medium',
                        'risk_reason' => 'فشار زمانی ممکن است scope نامشخص را پنهان کند.',
                        'detected_intent' => 'timeline_and_budget_check',
                        'next_stage' => $this->nextStage,
                        'reply_options' => [
                            [
                                'type' => 'short',
                                'target_text' => 'Yes, I can help. Could you confirm the must-have scope for Friday?',
                                'native_meaning' => 'بله، می‌توانم کمک کنم. لطفاً scope ضروری برای جمعه را تأیید کنید؟',
                            ],
                            [
                                'type' => 'professional',
                                'target_text' => 'I can help with this. To give you an accurate Friday delivery plan and cost, I need to confirm the exact scope and priority features first.',
                                'native_meaning' => 'می‌توانم کمک کنم. برای اینکه برنامه تحویل و هزینه دقیق برای جمعه بدهم، اول باید scope دقیق و featureهای اولویت‌دار را تأیید کنم.',
                            ],
                            [
                                'type' => 'closing',
                                'target_text' => 'This looks doable if we keep the first version focused. Send me the must-have scope and I can propose a clear Friday milestone with pricing.',
                                'native_meaning' => 'اگر نسخه اول را متمرکز نگه داریم، قابل انجام به نظر می‌رسد. scope ضروری را بفرستید تا milestone شفاف برای جمعه همراه قیمت پیشنهاد بدهم.',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];
            }
        });
    }

    private function createChattingClient(): int
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
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $clientId;
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
