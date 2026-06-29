<?php

namespace Tests\Feature;

use App\Services\Copilot\CopilotLanguageService;
use App\Services\Copilot\CopilotMessageService;
use App\Services\Telegram\TelegramMessageFormatter;
use Tests\TestCase;

class CopilotLocalizationFormattingTest extends TestCase
{
    public function test_static_messages_use_native_language_and_dynamic_platform_name(): void
    {
        config([
            'copilot.native_language' => 'fa',
            'copilot.target_language' => 'en',
            'copilot.target_platform_name' => 'Freelancer',
        ]);

        $formatter = $this->formatter();

        $this->assertStringNotContainsString('Welcome to ClientPilot AI', $formatter->startMessage());
        $this->assertStringContainsString('Freelancer', $formatter->startMessage());
        $this->assertStringContainsString('Freelancer', $formatter->startChatPromptMessage());
        $this->assertStringContainsString('Freelancer', $formatter->customReplyPromptMessage());
        $this->assertStringNotContainsString('from FreelanceHub', $formatter->startChatPromptMessage());
    }

    public function test_client_analysis_renders_native_and_target_when_languages_differ(): void
    {
        config([
            'copilot.native_language' => 'fa',
            'copilot.target_language' => 'en',
        ]);

        $message = $this->formatter()->clientAnalysisMessage($this->analysis([
            'client_type' => 'مشتری فنی',
            'client_type_target' => 'Technical client',
        ]));

        $this->assertStringContainsString('تحلیل مشتری', $message);
        $this->assertStringContainsString('مشتری فنی', $message);
        $this->assertStringContainsString('Technical client', $message);
    }

    public function test_client_analysis_does_not_duplicate_when_languages_match(): void
    {
        config([
            'copilot.native_language' => 'en',
            'copilot.target_language' => 'en',
        ]);

        $message = $this->formatter()->clientAnalysisMessage($this->analysis([
            'client_type' => 'Technical client',
            'client_type_target' => 'Duplicated target text',
        ]));

        $this->assertStringContainsString('Client Analysis', $message);
        $this->assertStringContainsString('Technical client', $message);
        $this->assertStringNotContainsString('Duplicated target text', $message);
    }

    private function formatter(): TelegramMessageFormatter
    {
        $languages = new CopilotLanguageService();

        return new TelegramMessageFormatter($languages, new CopilotMessageService($languages));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function analysis(array $overrides = []): array
    {
        return array_merge([
            'client_type' => 'مشتری فنی',
            'personality_type' => 'مستقیم',
            'main_need' => 'اتوماسیون',
            'best_strategy' => 'شفاف‌سازی scope',
            'risks' => 'scope نامشخص',
            'best_angle_for_mehrdad' => 'Laravel automation',
        ], $overrides);
    }
}
