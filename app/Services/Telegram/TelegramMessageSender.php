<?php

namespace App\Services\Telegram;

use App\Services\Ai\AiSensitiveDataMasker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramMessageSender
{
    public function __construct(
        private readonly AiSensitiveDataMasker $masker,
    ) {}

    /**
     * @param  array<string, mixed>  $replyMarkup
     * @return array<string, mixed>|null
     */
    public function sendMessage(int|string $chatId, string $text, array $replyMarkup = [], ?string $parseMode = null): ?array
    {
        $botToken = (string) config('telegram.bot_token');

        if ($botToken === '') {
            Log::warning('Telegram bot token is not configured. Message was not sent.');

            return null;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($parseMode !== null && $parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }

        if ($replyMarkup !== []) {
            $payload['reply_markup'] = $replyMarkup;
        }

        try {
            $response = Http::timeout(10)
                ->asJson()
                ->post("https://api.telegram.org/bot{$botToken}/sendMessage", $payload);

            if ($response->failed()) {
                Log::warning('Telegram sendMessage request failed.', [
                    'status' => $response->status(),
                    'body' => $this->masker->maskText($response->body()),
                ]);

                if ($parseMode !== null && $parseMode !== '') {
                    return $this->sendPlainFallback($botToken, $payload, $parseMode);
                }
            }

            return $response->json();
        } catch (Throwable $exception) {
            Log::error('Telegram sendMessage request threw an exception.', [
                'message' => $this->masker->maskText($exception->getMessage()),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function sendPlainFallback(string $botToken, array $payload, string $parseMode): ?array
    {
        unset($payload['parse_mode']);
        $payload['text'] = $this->plainFallbackText((string) ($payload['text'] ?? ''), $parseMode);

        try {
            $response = Http::timeout(10)
                ->asJson()
                ->post("https://api.telegram.org/bot{$botToken}/sendMessage", $payload);

            if ($response->failed()) {
                Log::warning('Telegram fallback sendMessage request failed.', [
                    'status' => $response->status(),
                    'body' => $this->masker->maskText($response->body()),
                ]);
            }

            return $response->json();
        } catch (Throwable $exception) {
            Log::error('Telegram fallback sendMessage request threw an exception.', [
                'message' => $this->masker->maskText($exception->getMessage()),
            ]);

            return null;
        }
    }

    private function plainFallbackText(string $text, string $parseMode): string
    {
        if (strtoupper($parseMode) !== 'HTML') {
            return $text;
        }

        $text = str_replace(['<pre>', '</pre>'], ["\n", "\n"], $text);

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): ?array
    {
        $botToken = (string) config('telegram.bot_token');

        if ($botToken === '') {
            Log::warning('Telegram bot token is not configured. Callback query was not answered.');

            return null;
        }

        $payload = [
            'callback_query_id' => $callbackQueryId,
        ];

        if ($text !== null && $text !== '') {
            $payload['text'] = $text;
        }

        try {
            $response = Http::timeout(10)
                ->asJson()
                ->post("https://api.telegram.org/bot{$botToken}/answerCallbackQuery", $payload);

            if ($response->failed()) {
                Log::warning('Telegram answerCallbackQuery request failed.', [
                    'status' => $response->status(),
                    'body' => $this->masker->maskText($response->body()),
                ]);
            }

            return $response->json();
        } catch (Throwable $exception) {
            Log::error('Telegram answerCallbackQuery request threw an exception.', [
                'message' => $this->masker->maskText($exception->getMessage()),
            ]);

            return null;
        }
    }
}
