<?php

namespace App\Services\Copilot;

class CopilotMessageService
{
    public function __construct(
        private readonly CopilotLanguageService $languages,
    ) {}

    /**
     * @param  array<string, scalar|null>  $replace
     */
    public function text(string $key, array $replace = []): string
    {
        $messages = $this->messages();
        $language = $this->languages->nativeLanguage();
        $text = $messages[$language][$key] ?? $messages['en'][$key] ?? $key;

        $replace = array_merge([
            'platform' => $this->languages->targetPlatformName(),
        ], $replace);

        foreach ($replace as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }

    public function riskLevelLabel(string $riskLevel): string
    {
        return $this->languages->riskLevelLabel($riskLevel);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function messages(): array
    {
        return [
            'fa' => [
                'start' => 'به دستیار مدیریت مکالمات :platform خوش آمدی. یک گزینه را از منو انتخاب کن.',
                'menu_prompt' => 'منوی اصلی:',
                'request_new_client_job' => 'توضیح job یا پیام اولیه مشتری را اینجا بفرست.',
                'analyzing_client' => 'در حال تحلیل...',
                'analyzing_client_reply' => 'در حال تحلیل پیام مشتری...',
                'ai_processing_in_progress' => 'تحلیل قبلی هنوز در حال انجام است. لطفاً چند لحظه دیگر صبر کن.',
                'ai_processing_failed' => 'در پردازش هوش مصنوعی خطایی رخ داد. پیام ذخیره شده است؛ لطفاً کمی بعد دوباره تلاش کن.',
                'regenerating_reply_suggestions' => 'در حال ساخت جواب‌های جدید...',
                'already_sent_cannot_regenerate' => "این پاسخ قبلاً به‌عنوان پاسخ ارسال‌شده ذخیره شده است.\nوقتی پیام بعدی مشتری را گرفتی، اینجا بفرست.",
                'future_sprint' => 'این بخش در Sprint بعدی پیاده‌سازی می‌شود.',
                'sent_reply_saved' => "به‌عنوان پاسخی که برای مشتری فرستادی ذخیره شد.\nوقتی پیام بعدی مشتری را گرفتی، اینجا بفرست.",
                'already_selected_reply' => "این پیشنهاد قبلاً ذخیره شده است.\nوقتی پیام بعدی مشتری را گرفتی، اینجا بفرست.",
                'stale_suggestion' => 'این پیشنهاد دیگر در دسترس نیست. پیام بعدی مشتری را بفرست تا گزینه‌های تازه ساخته شود.',
                'feedback_reason_prompt' => 'چرا از جواب‌ها راضی نیستی؟',
                'empty_feedback_reason' => 'لطفاً دلیل نارضایتی از جواب‌ها را بنویس.',
                'feedback_rejected_intro' => 'بازخوردت بررسی شد، اما بهتر است گزینه‌های قبلی حفظ شوند.',
                'reason_label' => 'دلیل:',
                'feedback_original_kept' => 'گزینه‌های قبلی همچنان قابل استفاده هستند.',
                'custom_reply_prompt' => 'متن دقیق پاسخی را که در :platform فرستادی اینجا paste کن.',
                'empty_custom_reply' => 'لطفاً متن دقیق پاسخی را که در :platform فرستادی paste کن.',
                'custom_reply_saved' => 'به‌عنوان پاسخ واقعی تو ذخیره شد.',
                'client_created' => "Client ساخته و فعال شد.\n\n#:id :title",
                'empty_client_message' => 'لطفاً یک توضیح job یا پیام اولیه غیرخالی بفرست.',
                'no_clients' => 'هنوز client نداری. از دکمه New Client استفاده کن.',
                'my_clients_title' => 'Clientهای من:',
                'active_suffix' => ' - فعال',
                'client_actions_apply' => 'اکشن‌های client روی client فعال اعمال می‌شوند.',
                'no_active_client' => 'هیچ client فعالی انتخاب نشده است. اول یک client جدید بساز.',
                'client_resumed' => "Client دوباره فعال شد.\n\n#:id :title",
                'client_paused' => "Client pause شد.\n\n#:id :title",
                'client_closed' => "Client بسته شد.\n\n#:id :title",
                'summary_placeholder' => "خلاصه موقت برای #:id :title.\n\nسیستم خلاصه‌سازی در این sprint پیاده‌سازی نشده است.",
                'summary_unavailable' => "هنوز خلاصه حافظه برای #:id :title وجود ندارد.\n\nبعد از آپدیت بعدی summary ساخته می‌شود.",
                'memory_summary_title' => 'خلاصه حافظه برای #:id :title',
                'summary_label' => 'خلاصه:',
                'current_context_label' => 'وضعیت فعلی:',
                'what_client_wants_label' => 'مشتری چه می‌خواهد:',
                'what_mehrdad_promised_label' => 'Mehrdad چه قولی داده:',
                'pricing_discussed_label' => 'قیمت/بودجه مطرح‌شده:',
                'deadline_discussed_label' => 'زمان‌بندی مطرح‌شده:',
                'access_needed_label' => 'دسترسی موردنیاز:',
                'open_questions_label' => 'سؤال‌های باز:',
                'risk_notes_label' => 'نکات ریسک:',
                'next_best_move_label' => 'بهترین قدم بعدی:',
                'last_message_id_label' => 'آخرین message ID:',
                'none_known' => 'هنوز چیزی مشخص نیست.',
                'none' => 'هیچ‌کدام',
                'client_analysis_title' => '🧠 تحلیل مشتری',
                'analysis_client_type' => 'نوع مشتری:',
                'analysis_main_need' => 'نیاز اصلی:',
                'analysis_personality' => 'شخصیت:',
                'analysis_best_strategy' => 'بهترین استراتژی:',
                'analysis_risks' => 'ریسک‌ها:',
                'analysis_best_angle' => 'بهترین زاویه برای Mehrdad:',
                'native_prefix' => '🇮🇷',
                'target_prefix' => '🇬🇧',
                'start_chat_prompt' => 'پیام بعدی مشتری را از :platform اینجا بفرست.',
                'unknown_command' => 'لطفاً از منوی زیر استفاده کن.',
                'access_denied' => 'دسترسی مجاز نیست.',
                'custom_risk_level' => 'سطح ریسک فعلی: :risk',
                'custom_pricing' => 'قیمت/بودجه مطرح شده؛ حواست به scope و milestone باشد.',
                'custom_deadline' => 'زمان‌بندی یا deadline مطرح شده؛ مراقب قول زمانی دقیق بدون scope کامل باش.',
                'custom_promise' => 'در متن، نشانه تعهد یا promise دیده شد؛ بعداً باید دقیقاً پیگیری شود.',
                'custom_access' => 'احتمالاً دسترسی/credential مطرح شده؛ فقط مسیر امن و ضروری را ادامه بده.',
                'custom_no_risk' => 'تعهد یا ریسک مهمی در متن تشخیص داده نشد.',
            ],
            'en' => [
                'start' => 'Welcome to Client Pilot for :platform. Choose an option from the menu.',
                'menu_prompt' => 'Main menu:',
                'request_new_client_job' => 'Send the job description or initial client message.',
                'analyzing_client' => 'Analyzing...',
                'analyzing_client_reply' => 'Analyzing the client message...',
                'ai_processing_in_progress' => 'The previous analysis is still running. Please wait a moment.',
                'ai_processing_failed' => 'AI processing failed. The message was saved; please try again shortly.',
                'regenerating_reply_suggestions' => 'Generating new replies...',
                'already_sent_cannot_regenerate' => "This reply was already saved as sent.\nPaste the next client message when you receive it.",
                'future_sprint' => 'This part will be implemented in a later sprint.',
                'sent_reply_saved' => "Saved as the reply you sent to client.\nPaste the next client message when you receive it.",
                'already_selected_reply' => "This suggestion was already saved.\nPaste the next client message when you receive it.",
                'stale_suggestion' => 'This suggestion is no longer available. Paste the next client message to generate fresh options.',
                'feedback_reason_prompt' => 'Why are you not satisfied with these replies?',
                'empty_feedback_reason' => 'Please write why you are not satisfied with these replies.',
                'feedback_rejected_intro' => 'Your feedback was reviewed, but it is safer to keep the original options.',
                'reason_label' => 'Reason:',
                'feedback_original_kept' => 'The original options are still available.',
                'custom_reply_prompt' => 'Paste the exact reply you manually sent on :platform.',
                'empty_custom_reply' => 'Please paste the exact reply you sent on :platform.',
                'custom_reply_saved' => 'Saved as your actual reply.',
                'client_created' => "Client created and activated.\n\n#:id :title",
                'empty_client_message' => 'Please send a non-empty job description or initial client message.',
                'no_clients' => 'No clients yet. Use New Client to create one.',
                'my_clients_title' => 'My Clients:',
                'active_suffix' => ' - active',
                'client_actions_apply' => 'Client actions apply to the active client.',
                'no_active_client' => 'No active client selected. Create a new client first.',
                'client_resumed' => "Client resumed.\n\n#:id :title",
                'client_paused' => "Client paused.\n\n#:id :title",
                'client_closed' => "Client closed.\n\n#:id :title",
                'summary_placeholder' => "Summary placeholder for #:id :title.\n\nSummary generation is not implemented in this sprint.",
                'summary_unavailable' => "No memory summary is available yet for #:id :title.\n\nIt will be created after the next summary update.",
                'memory_summary_title' => 'Memory Summary for #:id :title',
                'summary_label' => 'Summary:',
                'current_context_label' => 'Current context:',
                'what_client_wants_label' => 'What client wants:',
                'what_mehrdad_promised_label' => 'What Mehrdad promised:',
                'pricing_discussed_label' => 'Pricing discussed:',
                'deadline_discussed_label' => 'Deadline discussed:',
                'access_needed_label' => 'Access needed:',
                'open_questions_label' => 'Open questions:',
                'risk_notes_label' => 'Risk notes:',
                'next_best_move_label' => 'Next best move:',
                'last_message_id_label' => 'Last message ID:',
                'none_known' => 'None known yet.',
                'none' => 'None',
                'client_analysis_title' => '🧠 Client Analysis',
                'analysis_client_type' => 'Client type:',
                'analysis_main_need' => 'Main need:',
                'analysis_personality' => 'Personality:',
                'analysis_best_strategy' => 'Best strategy:',
                'analysis_risks' => 'Risks:',
                'analysis_best_angle' => 'Best angle for Mehrdad:',
                'native_prefix' => '🇺🇸',
                'target_prefix' => '🇬🇧',
                'start_chat_prompt' => 'Paste the next client message from :platform.',
                'unknown_command' => 'Please use the menu below.',
                'access_denied' => 'Access denied.',
                'custom_risk_level' => 'Current risk level: :risk',
                'custom_pricing' => 'Pricing/budget was discussed; keep scope and milestone clear.',
                'custom_deadline' => 'Timeline or deadline was discussed; avoid exact timing promises without full scope.',
                'custom_promise' => 'A commitment or promise appears in the text; follow it carefully later.',
                'custom_access' => 'Access or credentials may have been discussed; continue only through the safe and necessary path.',
                'custom_no_risk' => 'No important commitment or risk was detected in the text.',
            ],
        ];
    }
}
