<?php

namespace App\Services\Copilot;

class CopilotLanguageService
{
    public function nativeLanguage(): string
    {
        return $this->normalizeLanguage((string) config('copilot.native_language', 'fa'), 'fa');
    }

    public function targetLanguage(): string
    {
        return $this->normalizeLanguage((string) config('copilot.target_language', 'en'), 'en');
    }

    public function targetPlatformName(): string
    {
        $platform = trim((string) config('copilot.target_platform_name', 'FreelanceHub'));

        return $platform !== '' ? $platform : 'FreelanceHub';
    }

    public function isBilingual(): bool
    {
        return $this->nativeLanguage() !== $this->targetLanguage();
    }

    public function languageName(string $language): string
    {
        return match ($this->normalizeLanguage($language, $language)) {
            'fa' => 'Persian',
            'en' => 'English',
            default => $language,
        };
    }

    public function optionTypeLabel(string $type, int $optionNumber, string $fallback = ''): string
    {
        $label = match ($type) {
            'short' => 'Short',
            'professional' => 'Professional',
            'closing' => 'Closing / Sales-focused',
            default => $fallback,
        };

        return $label !== '' ? $label : "Option {$optionNumber}";
    }

    public function optionTypeFromLabel(string $label, int $optionNumber): string
    {
        $normalized = strtolower($label);

        if (str_contains($normalized, 'professional')) {
            return 'professional';
        }

        if (str_contains($normalized, 'closing') || str_contains($normalized, 'sales')) {
            return 'closing';
        }

        return match ($optionNumber) {
            2 => 'professional',
            3 => 'closing',
            default => 'short',
        };
    }

    public function optionTypeFromValue(mixed $value, int $optionNumber, string $fallbackLabel = ''): string
    {
        $type = strtolower(trim((string) $value));

        if (in_array($type, ['short', 'professional', 'closing'], true)) {
            return $type;
        }

        return $this->optionTypeFromLabel($fallbackLabel, $optionNumber);
    }

    public function text(string $key): string
    {
        $language = $this->nativeLanguage();

        $labels = [
            'fa' => [
                'client_read' => '🧠 برداشت از پیام مشتری:',
                'best_move' => '🎯 بهترین حرکت:',
                'risk' => '⚠️ ریسک:',
                'reply_options' => '💬 پیشنهادهای پاسخ:',
                'ready_to_send' => 'متن آماده ارسال:',
                'meaning' => 'معنی:',
                'high_risk_title' => '⚠️ هشدار ریسک بالا',
                'high_risk_body' => 'این پیام می‌تواند برای مسیر امن :platform ریسک داشته باشد.',
                'high_risk_safe_path' => 'مسیر امن: ارتباط و پرداخت داخل :platform، scope شفاف، و شروع فقط بعد از funded milestone.',
                'safe_best_move' => 'ارتباط و پرداخت را داخل :platform نگه دار، scope را شفاف کن، و فقط بعد از funded milestone شروع کن.',
                'safe_risk_reason' => 'ریسک امنیت مسیر :platform شناسایی شد.',
                'safe_unsafe_ai_output_suffix' => ' خروجی AI شامل پیشنهاد ناامن برای ارتباط، پرداخت، دسترسی، یا شروع قبل از milestone بود و به مسیر امن‌تر تغییر داده شد.',
                'closing_best_move' => 'مکالمه را به سمت scope شفاف، deliverable مشخص، timeline روشن، و funded :platform milestone هدایت کن.',
            ],
            'en' => [
                'client_read' => '🧠 Client read:',
                'best_move' => '🎯 Best move:',
                'risk' => '⚠️ Risk:',
                'reply_options' => '💬 Reply Options:',
                'ready_to_send' => 'Ready to send:',
                'meaning' => 'Meaning:',
                'high_risk_title' => '⚠️ High-risk warning',
                'high_risk_body' => 'This message may create risk for a safe :platform path.',
                'high_risk_safe_path' => 'Safe path: keep communication and payment on :platform, clarify scope, and start only after a funded milestone.',
                'safe_best_move' => 'Keep communication and payment on :platform, clarify scope, and start only after a funded milestone is in place.',
                'safe_risk_reason' => ':platform safety risk detected in reply generation.',
                'safe_unsafe_ai_output_suffix' => ' AI output contained unsafe contact, payment, access, or start-before-milestone language and was normalized to a safer path.',
                'closing_best_move' => 'Guide the conversation toward clear scope, deliverables, timeline, and a funded :platform milestone.',
            ],
        ];

        $text = $labels[$language][$key] ?? $labels['en'][$key] ?? $key;

        return str_replace(':platform', $this->targetPlatformName(), $text);
    }

    public function riskLevelLabel(string $riskLevel): string
    {
        $riskLevel = strtolower(trim($riskLevel));

        if ($this->nativeLanguage() !== 'fa') {
            return $riskLevel;
        }

        return match ($riskLevel) {
            'high' => 'بالا',
            'medium' => 'متوسط',
            'low' => 'پایین',
            default => $riskLevel,
        };
    }

    private function normalizeLanguage(string $language, string $fallback): string
    {
        $language = strtolower(trim($language));

        return $language !== '' ? $language : $fallback;
    }
}
