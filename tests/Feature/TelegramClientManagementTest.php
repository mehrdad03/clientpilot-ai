<?php

namespace Tests\Feature;

use App\Enums\ClientStage;
use App\Enums\ClientStatus;
use App\Enums\ConversationMessageType;
use App\Enums\ConversationSender;
use App\Services\Clients\ClientSessionManager;
use App\Services\Telegram\TelegramMessageFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TelegramClientManagementTest extends TestCase
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

        Queue::fake();
    }

    public function test_start_shows_main_menu(): void
    {
        $this->sendTelegramText(100, '/start')
            ->assertOk()
            ->assertJson(['ok' => true]);

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->startMessage()
                && data_get($request->data(), 'reply_markup.keyboard.0.0.text') === TelegramMessageFormatter::BUTTON_NEW_CLIENT
                && data_get($request->data(), 'reply_markup.keyboard.0.1.text') === TelegramMessageFormatter::BUTTON_MY_CLIENTS;
        });
    }

    public function test_new_client_emoji_button_starts_new_client_flow(): void
    {
        $this->sendTelegramText(101, "\u{2795} New Client")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('telegram_user_states', [
            'telegram_user_id' => 123456,
            'state' => ClientSessionManager::STATE_WAITING_FOR_NEW_CLIENT_JOB,
        ]);

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->requestNewClientJobMessage();
        });
    }

    public function test_new_client_ascii_button_starts_new_client_flow(): void
    {
        $this->sendTelegramText(102, '+ New Client')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('telegram_user_states', [
            'telegram_user_id' => 123456,
            'state' => ClientSessionManager::STATE_WAITING_FOR_NEW_CLIENT_JOB,
        ]);
    }

    public function test_new_client_plain_button_starts_new_client_flow(): void
    {
        $this->sendTelegramText(103, 'New Client')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('telegram_user_states', [
            'telegram_user_id' => 123456,
            'state' => ClientSessionManager::STATE_WAITING_FOR_NEW_CLIENT_JOB,
        ]);
    }


    public function test_new_client_symbol_variant_starts_new_client_flow(): void
    {
        $this->sendTelegramText(104, '✚ New Client')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('telegram_user_states', [
            'telegram_user_id' => 123456,
            'state' => ClientSessionManager::STATE_WAITING_FOR_NEW_CLIENT_JOB,
        ]);
    }

    public function test_my_clients_plain_button_shows_empty_state(): void
    {
        $this->sendTelegramText(105, 'My Clients')
            ->assertOk()
            ->assertJson(['ok' => true]);

        Http::assertSent(function ($request): bool {
            return data_get($request->data(), 'text') === app(TelegramMessageFormatter::class)->clientsListMessage(collect(), null);
        });
    }

    public function test_new_client_flow_creates_client_and_stores_initial_message(): void
    {
        $this->sendTelegramText(1, "\u{2795} New Client")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('telegram_user_states', [
            'telegram_user_id' => 123456,
            'state' => ClientSessionManager::STATE_WAITING_FOR_NEW_CLIENT_JOB,
        ]);

        $initialMessage = 'Need a Laravel developer for a Telegram CRM workflow.';

        $this->sendTelegramText(2, $initialMessage)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $client = DB::table('clients')->where('telegram_user_id', 123456)->first();

        $this->assertNotNull($client);
        $this->assertSame(ClientStatus::Active->value, $client->status);
        $this->assertSame(ClientStage::Intake->value, $client->stage);

        $this->assertDatabaseHas('conversation_messages', [
            'client_id' => $client->id,
            'telegram_user_id' => 123456,
            'sender' => ConversationSender::Client->value,
            'message_type' => ConversationMessageType::InitialJob->value,
            'body' => $initialMessage,
        ]);

        $this->assertDatabaseHas('telegram_user_states', [
            'telegram_user_id' => 123456,
            'state' => null,
            'active_client_id' => $client->id,
        ]);
    }

    public function test_my_clients_and_active_client_actions(): void
    {
        $this->sendTelegramText(10, TelegramMessageFormatter::BUTTON_NEW_CLIENT)->assertOk();
        $this->sendTelegramText(11, 'Build a Laravel dashboard for client marketplace leads.')->assertOk();

        $clientId = DB::table('clients')->value('id');

        $this->sendTelegramText(12, "\u{1F465} My Clients")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->sendTelegramText(13, TelegramMessageFormatter::BUTTON_PAUSE_CLIENT)->assertOk();
        $this->assertDatabaseHas('clients', [
            'id' => $clientId,
            'status' => ClientStatus::Paused->value,
        ]);

        $this->sendTelegramText(14, TelegramMessageFormatter::BUTTON_RESUME_CLIENT)->assertOk();
        $this->assertDatabaseHas('clients', [
            'id' => $clientId,
            'status' => ClientStatus::Active->value,
        ]);

        $this->sendTelegramText(15, TelegramMessageFormatter::BUTTON_VIEW_SUMMARY)->assertOk();

        Http::assertSent(function ($request): bool {
            $text = (string) data_get($request->data(), 'text');

            return str_contains($text, 'خلاصه')
                && str_contains($text, 'وجود ندارد');
        });

        $this->sendTelegramText(16, TelegramMessageFormatter::BUTTON_CLOSE_CLIENT)->assertOk();
        $this->assertDatabaseHas('clients', [
            'id' => $clientId,
            'status' => ClientStatus::Closed->value,
        ]);
    }

    public function test_my_clients_plain_button_still_works(): void
    {
        $this->sendTelegramText(20, TelegramMessageFormatter::BUTTON_NEW_CLIENT)->assertOk();
        $this->sendTelegramText(21, 'Build a Laravel dashboard for client marketplace leads.')->assertOk();

        $clientId = DB::table('clients')->value('id');

        $this->sendTelegramText(22, 'My Clients')
            ->assertOk()
            ->assertJson(['ok' => true]);

        Http::assertSent(function ($request) use ($clientId): bool {
            $text = (string) data_get($request->data(), 'text');

            return str_contains($text, "#{$clientId}")
                && str_contains($text, 'Laravel dashboard');
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
