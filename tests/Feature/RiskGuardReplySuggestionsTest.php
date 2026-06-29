<?php

namespace Tests\Feature;

use App\Contracts\Ai\AiProviderInterface;
use App\Enums\ClientStage;
use App\Enums\ClientStatus;
use App\Enums\ConversationMessageType;
use App\Enums\ConversationSender;
use App\Services\Clients\ClientSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RiskGuardReplySuggestionsTest extends TestCase
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

    public function test_high_risk_client_message_generates_freelancehub_safe_reply_options_and_warning(): void
    {
        $clientId = $this->createChattingClient();

        $this->bindReplyProvider([
            'client_read' => 'Client is pushing outside contact and payment.',
            'best_move' => 'Move to WhatsApp and start right away.',
            'risk_level' => 'low',
            'risk_reason' => 'No issue.',
            'detected_intent' => 'unsafe_fast_start',
            'next_stage' => ClientStage::Chatting->value,
            'reply_options' => [
                [
                    'label' => 'Short',
                    'body' => 'Sure, send me your WhatsApp and I can start before the milestone is funded.',
                ],
                [
                    'label' => 'Professional',
                    'body' => 'We can use email and PayPal to move faster.',
                ],
                [
                    'label' => 'Closing / Sales-focused',
                    'body' => 'Send the payment directly and I will begin today.',
                ],
            ],
        ]);

        $this->sendTelegramText(1, 'Can we move to WhatsApp? I can pay with PayPal. Please start today before I fund the milestone.')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $suggestion = DB::table('bot_suggestions')->where('client_id', $clientId)->first();

        $this->assertNotNull($suggestion);
        $this->assertSame('high', $suggestion->risk_level);
        $this->assertStringContainsString('FreelanceHub', $suggestion->best_move);
        $this->assertStringContainsString('ریسک امنیت مسیر FreelanceHub', $suggestion->risk_reason);

        $optionBodies = strtolower(DB::table('bot_suggestion_options')
            ->where('bot_suggestion_id', $suggestion->id)
            ->pluck('body')
            ->implode("\n"));

        foreach (['whatsapp', 'paypal', 'email', 'phone', 'telegram', 'bank transfer', 'direct payment'] as $unsafeTerm) {
            $this->assertStringNotContainsString($unsafeTerm, $optionBodies);
        }

        $this->assertStringContainsString('freelancehub', $optionBodies);
        $this->assertStringContainsString('funded', $optionBodies);
        $this->assertStringContainsString('milestone', $optionBodies);

        Http::assertSent(function ($request): bool {
            $text = (string) data_get($request->data(), 'text');

            return str_contains($text, 'هشدار ریسک بالا')
                && str_contains($text, 'funded milestone')
                && data_get($request->data(), 'parse_mode') === \App\Services\Telegram\TelegramMessageFormatter::PARSE_MODE_HTML;

            return str_contains($text, 'هشدار ریسک بالا')
                && str_contains($text, 'funded milestone');
        });
    }

    public function test_contract_closing_mode_guides_best_move_toward_funded_milestone(): void
    {
        $clientId = $this->createChattingClient();
        $provider = $this->bindReplyProvider([
            'client_read' => 'Client is ready to proceed.',
            'best_move' => 'Move the conversation toward a contract.',
            'risk_level' => 'low',
            'risk_reason' => 'No high-risk signal.',
            'detected_intent' => 'ready_to_close',
            'next_stage' => ClientStage::Chatting->value,
            'reply_options' => [
                [
                    'label' => 'Short',
                    'body' => 'Great, please confirm the exact scope and I can set up the first FreelanceHub milestone.',
                ],
                [
                    'label' => 'Professional',
                    'body' => 'Sounds good. Let us confirm the scope, deliverables, and timeline here, then we can proceed with the funded FreelanceHub milestone.',
                ],
                [
                    'label' => 'Closing / Sales-focused',
                    'body' => 'Perfect. The next step is a clear first milestone on FreelanceHub with the agreed deliverables and timeline.',
                ],
            ],
        ]);

        $this->sendTelegramText(2, "Sounds good, send the contract and let's start.")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $suggestion = DB::table('bot_suggestions')->where('client_id', $clientId)->first();

        $this->assertNotNull($suggestion);
        $this->assertSame('low', $suggestion->risk_level);
        $this->assertStringContainsString('funded FreelanceHub milestone', $suggestion->best_move);

        $this->assertTrue(collect($provider->inputs)->contains(
            fn (string $input): bool => str_contains($input, 'Contract closing mode: yes')
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function bindReplyProvider(array $payload): object
    {
        $provider = new class($payload) implements AiProviderInterface
        {
            /**
             * @var array<int, string>
             */
            public array $inputs = [];

            /**
             * @param  array<string, mixed>  $payload
             */
            public function __construct(private readonly array $payload) {}

            /**
             * @param  array<int, array<string, mixed>>|string  $input
             * @param  array<string, mixed>  $options
             * @return array<string, mixed>
             */
            public function createResponse(array|string $input, array $options = []): array
            {
                $this->inputs[] = is_array($input)
                    ? json_encode($input, JSON_THROW_ON_ERROR)
                    : $input;

                return [
                    'output_text' => json_encode($this->payload, JSON_THROW_ON_ERROR),
                ];
            }
        };

        $this->app->instance(AiProviderInterface::class, $provider);

        return $provider;
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
            'title' => 'Laravel CRM',
            'status' => ClientStatus::Active->value,
            'stage' => ClientStage::Chatting->value,
            'client_type' => 'Startup founder',
            'personality_type' => 'Direct',
            'main_need' => 'Build a scoped Laravel workflow',
            'best_strategy' => 'Keep scope tight and milestones clear.',
            'risk_level' => 'medium',
            'client_summary' => 'Client needs a Laravel workflow.',
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
            'body' => 'Need a Laravel workflow for managing leads.',
            'metadata' => null,
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
