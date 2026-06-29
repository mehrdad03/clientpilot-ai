<?php

namespace Tests\Feature;

use App\Contracts\Ai\AiProviderInterface;
use App\Enums\BotSuggestionStatus;
use App\Enums\ClientStage;
use App\Enums\ClientStatus;
use App\Enums\ConversationMessageType;
use App\Enums\ConversationSender;
use App\Services\Clients\ClientSessionManager;
use App\Services\Telegram\TelegramMessageFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class RegenerateReplySuggestionsTest extends TestCase
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

    public function test_regenerate_marks_old_suggestion_and_creates_new_generated_options(): void
    {
        $fixture = $this->createSuggestionFixture();
        $this->bindReplyProvider($this->replyPayload('Regenerated short reply.'));

        $this->sendTelegramText(1, TelegramMessageFormatter::BUTTON_REGENERATE)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('bot_suggestions', [
            'id' => $fixture['suggestion_id'],
            'status' => BotSuggestionStatus::Regenerated->value,
        ]);

        $newSuggestion = DB::table('bot_suggestions')
            ->where('id', '!=', $fixture['suggestion_id'])
            ->where('client_id', $fixture['client_id'])
            ->first();

        $this->assertNotNull($newSuggestion);
        $this->assertSame(BotSuggestionStatus::Generated->value, $newSuggestion->status);
        $this->assertSame($fixture['message_id'], $newSuggestion->conversation_message_id);
        $this->assertSame(3, DB::table('bot_suggestion_options')->where('bot_suggestion_id', $newSuggestion->id)->count());
        $this->assertDatabaseHas('bot_suggestion_options', [
            'bot_suggestion_id' => $newSuggestion->id,
            'option_number' => 1,
            'type' => 'short',
            'body' => 'Regenerated short reply.',
            'native_meaning' => 'پاسخ کوتاه بازسازی‌شده.',
        ]);

        $this->assertDatabaseHas('telegram_user_states', [
            'telegram_user_id' => 123456,
            'state' => ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
            'active_client_id' => $fixture['client_id'],
        ]);

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->regeneratingReplySuggestionsMessage();
        });

        Http::assertSent(function ($request): bool {
            return str_contains((string) data_get($request->data(), 'text'), '<pre>Regenerated short reply.</pre>')
                && str_contains((string) data_get($request->data(), 'text'), 'پاسخ کوتاه بازسازی‌شده.')
                && data_get($request->data(), 'parse_mode') === TelegramMessageFormatter::PARSE_MODE_HTML
                && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.text') === TelegramMessageFormatter::BUTTON_SENT_OPTION_1
                && str_starts_with((string) data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data'), 'sg:sel:');
        });
    }

    public function test_regenerate_does_not_touch_selected_suggestion(): void
    {
        $fixture = $this->createSuggestionFixture(status: BotSuggestionStatus::Selected->value, selected: true);
        $this->bindReplyProvider($this->replyPayload('This should not be generated.'));

        $this->sendTelegramText(2, TelegramMessageFormatter::BUTTON_REGENERATE)->assertOk();

        $this->assertDatabaseHas('bot_suggestions', [
            'id' => $fixture['suggestion_id'],
            'status' => BotSuggestionStatus::Selected->value,
            'selected_option_id' => $fixture['option_id'],
            'selected_text' => 'Original option body.',
        ]);
        $this->assertSame(1, DB::table('bot_suggestions')->where('client_id', $fixture['client_id'])->count());
        $this->assertSame(0, DB::table('ai_requests')->count());

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->alreadySentCannotRegenerateMessage();
        });
    }

    public function test_regenerate_handles_stale_suggestion_without_ai_call(): void
    {
        $fixture = $this->createSuggestionFixture(status: BotSuggestionStatus::Regenerated->value);
        $this->bindReplyProvider($this->replyPayload('This should not be generated.'));

        $this->sendTelegramText(3, TelegramMessageFormatter::BUTTON_REGENERATE)->assertOk();

        $this->assertDatabaseHas('bot_suggestions', [
            'id' => $fixture['suggestion_id'],
            'status' => BotSuggestionStatus::Regenerated->value,
        ]);
        $this->assertSame(1, DB::table('bot_suggestions')->where('client_id', $fixture['client_id'])->count());
        $this->assertSame(0, DB::table('ai_requests')->count());

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->staleSuggestionMessage();
        });
    }

    public function test_regenerate_uses_client_ai_processing_lock(): void
    {
        $fixture = $this->createSuggestionFixture();
        $this->bindReplyProvider($this->replyPayload('This should wait.'));
        $lock = Cache::lock('client-ai-processing:'.$fixture['client_id'], 180);
        $this->assertTrue($lock->get());

        try {
            $this->sendTelegramText(4, TelegramMessageFormatter::BUTTON_REGENERATE)->assertOk();

            $this->assertDatabaseHas('bot_suggestions', [
                'id' => $fixture['suggestion_id'],
                'status' => BotSuggestionStatus::Generated->value,
            ]);
            $this->assertSame(1, DB::table('bot_suggestions')->where('client_id', $fixture['client_id'])->count());
            $this->assertSame(0, DB::table('ai_requests')->count());

            Http::assertSent(function ($request): bool {
                return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->aiProcessingInProgressMessage();
            });
        } finally {
            $lock->release();
        }
    }

    public function test_failed_regenerate_keeps_old_suggestion_generated(): void
    {
        $fixture = $this->createSuggestionFixture();

        $this->app->instance(AiProviderInterface::class, new class implements AiProviderInterface
        {
            public function createResponse(array|string $input, array $options = []): array
            {
                throw new RuntimeException('Regenerate failed.');
            }
        });

        $this->sendTelegramText(5, TelegramMessageFormatter::BUTTON_REGENERATE)->assertOk();

        $this->assertDatabaseHas('bot_suggestions', [
            'id' => $fixture['suggestion_id'],
            'status' => BotSuggestionStatus::Generated->value,
        ]);
        $this->assertSame(1, DB::table('bot_suggestions')->where('client_id', $fixture['client_id'])->count());

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->aiProcessingFailedMessage();
        });
    }

    public function test_regenerate_reuses_risk_guard_for_new_options(): void
    {
        $fixture = $this->createSuggestionFixture(clientMessageBody: 'Can we move to WhatsApp and pay with PayPal before funding the milestone?');
        $this->bindReplyProvider($this->replyPayload('Sure, WhatsApp and PayPal are fine.'));

        $this->sendTelegramText(6, TelegramMessageFormatter::BUTTON_REGENERATE)->assertOk();

        $newSuggestion = DB::table('bot_suggestions')
            ->where('id', '!=', $fixture['suggestion_id'])
            ->where('client_id', $fixture['client_id'])
            ->first();

        $this->assertNotNull($newSuggestion);
        $this->assertSame('high', $newSuggestion->risk_level);

        $optionBodies = strtolower(DB::table('bot_suggestion_options')
            ->where('bot_suggestion_id', $newSuggestion->id)
            ->pluck('body')
            ->implode("\n"));

        $this->assertStringNotContainsString('whatsapp', $optionBodies);
        $this->assertStringNotContainsString('paypal', $optionBodies);
        $this->assertStringContainsString('freelancehub', $optionBodies);
        $this->assertStringContainsString('funded', $optionBodies);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function bindReplyProvider(array $payload): void
    {
        $this->app->instance(AiProviderInterface::class, new class($payload) implements AiProviderInterface
        {
            /**
             * @param  array<string, mixed>  $payload
             */
            public function __construct(private readonly array $payload) {}

            public function createResponse(array|string $input, array $options = []): array
            {
                return [
                    'output_text' => json_encode($this->payload, JSON_THROW_ON_ERROR),
                ];
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function replyPayload(string $shortOptionBody): array
    {
        return [
            'client_read' => 'مشتری پاسخ بازنویسی‌شده می‌خواهد.',
            'best_move' => 'scope را شفاف کن و قدم بعدی را ساده نگه دار.',
            'risk_level' => 'low',
            'risk_reason' => 'نشانه ریسک بالا دیده نمی‌شود.',
            'detected_intent' => 'regenerate_reply_options',
            'next_stage' => ClientStage::Chatting->value,
            'reply_options' => [
                [
                    'type' => 'short',
                    'target_text' => $shortOptionBody,
                    'native_meaning' => $shortOptionBody === 'Regenerated short reply.' ? 'پاسخ کوتاه بازسازی‌شده.' : 'این پاسخ کوتاه باید منتظر بماند.',
                ],
                [
                    'type' => 'professional',
                    'target_text' => 'I can help. Let me confirm the exact scope and timeline before we move forward.',
                    'native_meaning' => 'می‌توانم کمک کنم. قبل از ادامه، اجازه بدهید scope دقیق و timeline را تأیید کنم.',
                ],
                [
                    'type' => 'closing',
                    'target_text' => 'This can work well if we keep the first milestone focused and clearly defined.',
                    'native_meaning' => 'اگر milestone اول را متمرکز و شفاف تعریف کنیم، این می‌تواند خوب پیش برود.',
                ],
            ],
        ];
    }

    /**
     * @return array{client_id: int, message_id: int, suggestion_id: int, option_id: int}
     */
    private function createSuggestionFixture(
        string $status = BotSuggestionStatus::Generated->value,
        bool $selected = false,
        string $clientMessageBody = 'Can you rewrite this reply option?'
    ): array {
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
            'title' => 'Laravel CRM',
            'status' => ClientStatus::Active->value,
            'stage' => ClientStage::Chatting->value,
            'client_type' => 'Startup founder',
            'personality_type' => 'Direct',
            'main_need' => 'Build a Laravel workflow',
            'best_strategy' => 'Keep scope tight.',
            'risk_level' => 'medium',
            'client_summary' => 'Client needs Laravel help.',
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
            'body' => 'Need Laravel help.',
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $messageId = DB::table('conversation_messages')->insertGetId([
            'client_id' => $clientId,
            'telegram_user_id' => 123456,
            'sender' => ConversationSender::Client->value,
            'message_type' => ConversationMessageType::ClientMessage->value,
            'body' => $clientMessageBody,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $suggestionId = DB::table('bot_suggestions')->insertGetId([
            'client_id' => $clientId,
            'conversation_message_id' => $messageId,
            'telegram_user_id' => 123456,
            'client_read' => 'Client wants a reply.',
            'best_move' => 'Provide a clear answer.',
            'risk_level' => 'low',
            'risk_reason' => 'No risk.',
            'detected_intent' => 'reply_needed',
            'next_stage' => ClientStage::Chatting->value,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $optionId = DB::table('bot_suggestion_options')->insertGetId([
            'bot_suggestion_id' => $suggestionId,
            'option_number' => 1,
            'label' => 'Short',
            'body' => 'Original option body.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($selected) {
            DB::table('bot_suggestions')
                ->where('id', $suggestionId)
                ->update([
                    'selected_option_id' => $optionId,
                    'selected_text' => 'Original option body.',
                    'selected_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        return [
            'client_id' => $clientId,
            'message_id' => $messageId,
            'suggestion_id' => $suggestionId,
            'option_id' => $optionId,
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
