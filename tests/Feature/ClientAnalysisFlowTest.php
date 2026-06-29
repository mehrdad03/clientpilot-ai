<?php

namespace Tests\Feature;

use App\Contracts\Ai\AiProviderInterface;
use App\Enums\AiRequestStatus;
use App\Enums\ClientStage;
use App\Enums\ConversationMessageType;
use App\Enums\ConversationSender;
use App\Services\Clients\ClientSessionManager;
use App\Services\Telegram\TelegramMessageFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ClientAnalysisFlowTest extends TestCase
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
                        'title' => 'Laravel Telegram CRM',
                        'client_type' => [
                            'native_text' => 'بنیان‌گذار استارتاپ',
                            'target_text' => 'Startup founder',
                        ],
                        'personality_type' => [
                            'native_text' => 'مستقیم و نتیجه‌محور',
                            'target_text' => 'Direct and outcome-focused',
                        ],
                        'main_need' => [
                            'native_text' => 'ساخت workflow CRM مبتنی بر Telegram',
                            'target_text' => 'Build a Telegram-based CRM workflow',
                        ],
                        'best_strategy' => [
                            'native_text' => 'با MVP کوچک، قابل اتکا و milestoneهای شفاف جلو برو.',
                            'target_text' => 'Lead with a small reliable MVP and clear delivery milestones.',
                        ],
                        'risk_level' => 'medium',
                        'client_summary' => [
                            'native_text' => 'مشتری یک CRM تلگرامی عملی برای مدیریت leadها می‌خواهد.',
                            'target_text' => 'Client wants a practical Telegram CRM for managing platform leads.',
                        ],
                        'best_angle_for_mehrdad' => [
                            'native_text' => 'Mehrdad را به عنوان Laravel automation partner سریع و عملی معرفی کن.',
                            'target_text' => 'Position Mehrdad as a Laravel automation partner who can ship quickly.',
                        ],
                        'risks' => [
                            'native_text' => 'اگر مرزهای CRM زود مشخص نشود، scope می‌تواند بزرگ شود.',
                            'target_text' => 'Scope can expand if CRM boundaries are not defined early.',
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];
            }
        });
    }

    public function test_new_client_dispatches_analysis_and_sends_start_chat_button(): void
    {
        $this->sendTelegramText(1, TelegramMessageFormatter::BUTTON_NEW_CLIENT)->assertOk();
        $this->sendTelegramText(2, 'Need a Laravel expert to build a Telegram CRM for client marketplace leads.')->assertOk();

        $client = DB::table('clients')->where('telegram_user_id', 123456)->first();

        $this->assertNotNull($client);
        $this->assertSame('Laravel Telegram CRM', $client->title);
        $this->assertSame('بنیان‌گذار استارتاپ', $client->client_type);
        $this->assertSame('مستقیم و نتیجه‌محور', $client->personality_type);
        $this->assertSame('ساخت workflow CRM مبتنی بر Telegram', $client->main_need);
        $this->assertSame('medium', $client->risk_level);
        $this->assertSame(ClientStage::Analyzed->value, $client->stage);

        $this->assertDatabaseHas('conversation_messages', [
            'client_id' => $client->id,
            'telegram_user_id' => 123456,
            'sender' => ConversationSender::Bot->value,
            'message_type' => ConversationMessageType::BotAnalysis->value,
        ]);

        $this->assertDatabaseHas('ai_requests', [
            'provider' => 'openai',
            'prompt_key' => 'sales_copilot_analysis',
            'prompt_version' => 'v1',
            'status' => AiRequestStatus::Succeeded->value,
        ]);

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->analyzingClientMessage();
        });

        Http::assertSent(function ($request): bool {
            $text = (string) data_get($request->data(), 'text');

            return str_contains($text, 'تحلیل مشتری')
                && str_contains($text, 'بنیان‌گذار استارتاپ')
                && str_contains($text, 'Startup founder')
                && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.text') === TelegramMessageFormatter::BUTTON_START_CHAT
                && str_starts_with((string) data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data'), 'chat:start:');
        });
    }

    public function test_start_chat_sets_state_and_moves_analyzed_client_to_chatting(): void
    {
        $this->sendTelegramText(10, TelegramMessageFormatter::BUTTON_NEW_CLIENT)->assertOk();
        $this->sendTelegramText(11, 'Need a Telegram CRM for client marketplace leads.')->assertOk();

        $clientId = DB::table('clients')->value('id');

        $this->sendTelegramText(12, TelegramMessageFormatter::BUTTON_START_CHAT)->assertOk();

        $this->assertDatabaseHas('telegram_user_states', [
            'telegram_user_id' => 123456,
            'state' => ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
            'active_client_id' => $clientId,
        ]);

        $this->assertDatabaseHas('clients', [
            'id' => $clientId,
            'stage' => ClientStage::Chatting->value,
        ]);

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->startChatPromptMessage();
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
}
