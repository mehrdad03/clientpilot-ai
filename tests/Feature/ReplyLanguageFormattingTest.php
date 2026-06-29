<?php

namespace Tests\Feature;

use App\Models\BotSuggestion;
use App\Models\BotSuggestionOption;
use App\Services\Copilot\CopilotLanguageService;
use App\Services\Copilot\CopilotMessageService;
use App\Services\Telegram\TelegramMessageFormatter;
use Tests\TestCase;

class ReplyLanguageFormattingTest extends TestCase
{
    public function test_bilingual_reply_options_render_target_text_in_pre_and_native_meaning(): void
    {
        config([
            'copilot.native_language' => 'fa',
            'copilot.target_language' => 'en',
        ]);

        $message = $this->formatter()->replySuggestionsMessage(
            $this->suggestion(),
            [
                new BotSuggestionOption([
                    'option_number' => 1,
                    'label' => 'Short',
                    'type' => 'short',
                    'body' => 'Hello <client> & welcome.',
                    'native_meaning' => 'سلام به مشتری و خوشامدگویی.',
                ]),
            ]
        );

        $this->assertStringContainsString('برداشت از پیام مشتری', $message);
        $this->assertStringContainsString('<pre>Hello &lt;client&gt; &amp; welcome.</pre>', $message);
        $this->assertStringContainsString('معنی:', $message);
        $this->assertStringContainsString('سلام به مشتری و خوشامدگویی.', $message);
        $this->assertStringNotContainsString('Hello <client> & welcome.', $message);
    }

    public function test_same_native_and_target_language_does_not_duplicate_native_meaning(): void
    {
        config([
            'copilot.native_language' => 'en',
            'copilot.target_language' => 'en',
        ]);

        $message = $this->formatter()->replySuggestionsMessage(
            $this->suggestion(),
            [
                new BotSuggestionOption([
                    'option_number' => 1,
                    'label' => 'Short',
                    'type' => 'short',
                    'body' => 'Thanks, please share the scope.',
                    'native_meaning' => 'Duplicate translation should not be shown.',
                ]),
            ]
        );

        $this->assertStringContainsString('Client read:', $message);
        $this->assertStringContainsString('<pre>Thanks, please share the scope.</pre>', $message);
        $this->assertStringNotContainsString('Meaning:', $message);
        $this->assertStringNotContainsString('Duplicate translation should not be shown.', $message);
    }

    private function formatter(): TelegramMessageFormatter
    {
        $languages = new CopilotLanguageService();

        return new TelegramMessageFormatter($languages, new CopilotMessageService($languages));
    }

    private function suggestion(): BotSuggestion
    {
        return new BotSuggestion([
            'client_read' => 'مشتری scope را می‌خواهد.',
            'best_move' => 'scope را شفاف کن.',
            'risk_level' => 'low',
            'risk_reason' => 'ریسک مهمی دیده نشد.',
        ]);
    }
}
