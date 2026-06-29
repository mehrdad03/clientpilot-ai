<?php

namespace App\Services\Telegram;

use App\Models\BotSuggestion;
use App\Models\BotSuggestionOption;
use App\Models\ClientSummary;
use App\Services\Copilot\CopilotLanguageService;
use App\Services\Copilot\CopilotMessageService;
use Illuminate\Support\Collection;

class TelegramMessageFormatter
{
    public const PARSE_MODE_HTML = 'HTML';

    public const BUTTON_NEW_CLIENT = '➕ New Client';

    public const BUTTON_MY_CLIENTS = '👥 My Clients';

    public const BUTTON_RESUME_CLIENT = '▶️ Resume Client';

    public const BUTTON_PAUSE_CLIENT = '⏸ Pause Client';

    public const BUTTON_CLOSE_CLIENT = '✅ Close Client';

    public const BUTTON_VIEW_SUMMARY = '📄 View Summary';

    public const BUTTON_START_CHAT = '💬 Start Chat';

    public const BUTTON_SENT_OPTION_1 = '✅ Sent Option 1';

    public const BUTTON_SENT_OPTION_2 = '✅ Sent Option 2';

    public const BUTTON_SENT_OPTION_3 = '✅ Sent Option 3';

    public const BUTTON_REGENERATE = '🔁 Regenerate';

    public const BUTTON_DISLIKE_REPLIES = '📝 I don’t like these replies';

    public const BUTTON_OWN_REPLY = '✍️ I wrote my own reply';

    public const BUTTON_PAUSE_CLIENT_FINISH = '🏁 Pause Client';

    public function __construct(
        private readonly CopilotLanguageService $languages,
        private readonly CopilotMessageService $messages,
    ) {}

    public function startMessage(): string
    {
        return $this->messages->text('start');
    }

    public function menuPrompt(): string
    {
        return $this->messages->text('menu_prompt');
    }

    public function requestNewClientJobMessage(): string
    {
        return $this->messages->text('request_new_client_job');
    }

    public function analyzingClientMessage(): string
    {
        return $this->messages->text('analyzing_client');
    }

    public function analyzingClientReplyMessage(): string
    {
        return $this->messages->text('analyzing_client_reply');
    }

    public function aiProcessingInProgressMessage(): string
    {
        return $this->messages->text('ai_processing_in_progress');
    }

    public function aiProcessingFailedMessage(): string
    {
        return $this->messages->text('ai_processing_failed');
    }

    public function regeneratingReplySuggestionsMessage(): string
    {
        return $this->messages->text('regenerating_reply_suggestions');
    }

    public function alreadySentCannotRegenerateMessage(): string
    {
        return $this->messages->text('already_sent_cannot_regenerate');
    }

    public function futureSprintMessage(): string
    {
        return $this->messages->text('future_sprint');
    }

    public function sentReplySavedMessage(): string
    {
        return $this->messages->text('sent_reply_saved');
    }

    public function alreadySelectedReplyMessage(): string
    {
        return $this->messages->text('already_selected_reply');
    }

    public function staleSuggestionMessage(): string
    {
        return $this->messages->text('stale_suggestion');
    }

    public function feedbackReasonPromptMessage(): string
    {
        return $this->messages->text('feedback_reason_prompt');
    }

    public function emptyFeedbackReasonMessage(): string
    {
        return $this->messages->text('empty_feedback_reason');
    }

    public function feedbackRejectedMessage(string $reason): string
    {
        return implode("\n", [
            $this->messages->text('feedback_rejected_intro'),
            '',
            $this->messages->text('reason_label'),
            $reason,
            '',
            $this->messages->text('feedback_original_kept'),
        ]);
    }

    public function customReplyPromptMessage(): string
    {
        return $this->messages->text('custom_reply_prompt');
    }

    public function emptyCustomReplyMessage(): string
    {
        return $this->messages->text('empty_custom_reply');
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    public function customReplySavedMessage(array $analysis): string
    {
        return implode("\n", [
            $this->messages->text('custom_reply_saved'),
            '',
            $this->customReplyRiskSummary($analysis),
            '',
            $this->messages->text('start_chat_prompt'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $client
     */
    public function clientCreatedMessage(array $client): string
    {
        return $this->messages->text('client_created', [
            'id' => $client['id'],
            'title' => $client['title'],
        ]);
    }

    public function emptyClientMessage(): string
    {
        return $this->messages->text('empty_client_message');
    }

    /**
     * @param  Collection<int, object>  $clients
     */
    public function clientsListMessage(Collection $clients, int|string|null $activeClientId): string
    {
        if ($clients->isEmpty()) {
            return $this->messages->text('no_clients');
        }

        $lines = [$this->messages->text('my_clients_title')];

        foreach ($clients as $client) {
            $activeLabel = (string) $client->id === (string) $activeClientId ? $this->messages->text('active_suffix') : '';
            $lines[] = "#{$client->id} {$client->title} [{$client->status}]{$activeLabel}";
        }

        $lines[] = '';
        $lines[] = $this->messages->text('client_actions_apply');

        return implode("\n", $lines);
    }

    public function noActiveClientMessage(): string
    {
        return $this->messages->text('no_active_client');
    }

    /**
     * @param  array<string, mixed>  $client
     */
    public function clientResumedMessage(array $client): string
    {
        return $this->messages->text('client_resumed', [
            'id' => $client['id'],
            'title' => $client['title'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $client
     */
    public function clientPausedMessage(array $client): string
    {
        return $this->messages->text('client_paused', [
            'id' => $client['id'],
            'title' => $client['title'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $client
     */
    public function clientClosedMessage(array $client): string
    {
        return $this->messages->text('client_closed', [
            'id' => $client['id'],
            'title' => $client['title'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $client
     */
    public function summaryPlaceholderMessage(array $client): string
    {
        return $this->messages->text('summary_placeholder', [
            'id' => $client['id'],
            'title' => $client['title'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $client
     */
    public function summaryUnavailableMessage(array $client): string
    {
        return $this->messages->text('summary_unavailable', [
            'id' => $client['id'],
            'title' => $client['title'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $client
     */
    public function clientSummaryMessage(array $client, ClientSummary $summary): string
    {
        return implode("\n", [
            $this->messages->text('memory_summary_title', [
                'id' => $client['id'],
                'title' => $client['title'],
            ]),
            '',
            $this->messages->text('summary_label'),
            $summary->summary ?: $this->messages->text('none_known'),
            '',
            $this->messages->text('current_context_label'),
            $summary->current_context ?: $this->messages->text('none_known'),
            '',
            $this->messages->text('what_client_wants_label'),
            $summary->what_client_wants ?: $this->messages->text('none_known'),
            '',
            $this->messages->text('what_mehrdad_promised_label'),
            $summary->what_mehrdad_promised ?: $this->messages->text('none_known'),
            '',
            $this->messages->text('pricing_discussed_label'),
            $summary->pricing_discussed ?: $this->messages->text('none_known'),
            '',
            $this->messages->text('deadline_discussed_label'),
            $summary->deadline_discussed ?: $this->messages->text('none_known'),
            '',
            $this->messages->text('access_needed_label'),
            $summary->access_needed ?: $this->messages->text('none_known'),
            '',
            $this->messages->text('open_questions_label'),
            $summary->open_questions ?: $this->messages->text('none_known'),
            '',
            $this->messages->text('risk_notes_label'),
            $summary->risk_notes ?: $this->messages->text('none_known'),
            '',
            $this->messages->text('next_best_move_label'),
            $summary->next_best_move ?: $this->messages->text('none_known'),
            '',
            $this->messages->text('last_message_id_label'),
            (string) ($summary->last_message_id ?? $this->messages->text('none')),
        ]);
    }

    /**
     * @param  array<string, string>  $analysis
     */
    public function clientAnalysisMessage(array $analysis): string
    {
        $lines = [
            $this->messages->text('client_analysis_title'),
            '',
        ];

        $this->appendAnalysisField($lines, 'analysis_client_type', $analysis, 'client_type');
        $this->appendAnalysisField($lines, 'analysis_main_need', $analysis, 'main_need');
        $this->appendAnalysisField($lines, 'analysis_personality', $analysis, 'personality_type');
        $this->appendAnalysisField($lines, 'analysis_best_strategy', $analysis, 'best_strategy');
        $this->appendAnalysisField($lines, 'analysis_risks', $analysis, 'risks');
        $this->appendAnalysisField($lines, 'analysis_best_angle', $analysis, 'best_angle_for_mehrdad');

        return rtrim(implode("\n", $lines));
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<string, mixed>  $analysis
     */
    private function appendAnalysisField(array &$lines, string $labelKey, array $analysis, string $field): void
    {
        $nativeText = trim((string) ($analysis[$field] ?? ''));
        $targetText = trim((string) ($analysis[$field.'_target'] ?? ''));

        $lines[] = $this->messages->text($labelKey);

        if ($this->languages->isBilingual()) {
            $lines[] = trim($this->messages->text('native_prefix').' '.$nativeText);

            if ($targetText !== '') {
                $lines[] = trim($this->messages->text('target_prefix').' '.$targetText);
            }
        } else {
            $lines[] = $nativeText;
        }

        $lines[] = '';
    }

    /**
     * @param  array<int, BotSuggestionOption>  $options
     */
    public function replySuggestionsMessage(BotSuggestion $suggestion, array $options): string
    {
        return $this->replySuggestionsHtmlMessage($suggestion, $options);

        $optionLines = [];

        foreach ($options as $option) {
            $optionLines[] = "Option {$option->option_number} — {$option->label}";
            $optionLines[] = $option->body;
            $optionLines[] = '';
        }

        $riskWarningLines = $this->highRiskWarningLines($suggestion);

        return rtrim(implode("\n", array_merge($riskWarningLines, [
            '🧠 Client read:',
            $suggestion->client_read,
            '',
            '🎯 Best move:',
            $suggestion->best_move,
            '',
            '⚠️ Risk:',
            trim(($suggestion->risk_level ?? '').' - '.($suggestion->risk_reason ?? ''), ' -'),
            '',
            '💬 Reply Options:',
        ], $optionLines)));
    }

    /**
     * @param  array<int, BotSuggestionOption>  $options
     */
    private function replySuggestionsHtmlMessage(BotSuggestion $suggestion, array $options): string
    {
        $optionLines = [];

        foreach ($options as $option) {
            $type = (string) ($option->type ?? '');
            $label = $this->languages->optionTypeLabel($type, (int) $option->option_number, (string) $option->label);

            $optionLines[] = $this->html("Option {$option->option_number} — {$label}");
            $optionLines[] = '';
            $optionLines[] = $this->html($this->languages->text('ready_to_send'));
            $optionLines[] = '<pre>'.$this->html((string) $option->body).'</pre>';

            if ($this->languages->isBilingual() && $option->native_meaning !== null && $option->native_meaning !== '') {
                $optionLines[] = $this->html($this->languages->text('meaning'));
                $optionLines[] = $this->html((string) $option->native_meaning);
            }

            $optionLines[] = '';
        }

        $riskText = trim($this->languages->riskLevelLabel((string) ($suggestion->risk_level ?? '')).' - '.($suggestion->risk_reason ?? ''), ' -');

        return rtrim(implode("\n", array_merge($this->highRiskWarningLines($suggestion), [
            $this->html($this->languages->text('client_read')),
            $this->html((string) $suggestion->client_read),
            '',
            $this->html($this->languages->text('best_move')),
            $this->html((string) $suggestion->best_move),
            '',
            $this->html($this->languages->text('risk')),
            $this->html($riskText),
            '',
            $this->html($this->languages->text('reply_options')),
            '',
        ], $optionLines)));
    }

    private function html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public function startChatPromptMessage(): string
    {
        return $this->messages->text('start_chat_prompt');
    }

    public function unknownCommandMessage(): string
    {
        return $this->messages->text('unknown_command');
    }

    public function accessDeniedMessage(): string
    {
        return $this->messages->text('access_denied');
    }

    /**
     * @return array<string, mixed>
     */
    public function mainMenuKeyboard(): array
    {
        return [
            'keyboard' => [
                [
                    ['text' => self::BUTTON_NEW_CLIENT],
                    ['text' => self::BUTTON_MY_CLIENTS],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function clientActionsKeyboard(int|string|null $clientId = null): array
    {
        if ($clientId !== null && $clientId !== '') {
            return [
                'inline_keyboard' => [
                    [
                        ['text' => self::BUTTON_RESUME_CLIENT, 'callback_data' => "cl:rs:{$clientId}"],
                        ['text' => self::BUTTON_PAUSE_CLIENT, 'callback_data' => "cl:pa:{$clientId}"],
                    ],
                    [
                        ['text' => self::BUTTON_CLOSE_CLIENT, 'callback_data' => "cl:cl:{$clientId}"],
                        ['text' => self::BUTTON_VIEW_SUMMARY, 'callback_data' => "cl:sum:{$clientId}"],
                    ],
                ],
            ];
        }

        return [
            'keyboard' => [
                [
                    ['text' => self::BUTTON_RESUME_CLIENT],
                    ['text' => self::BUTTON_PAUSE_CLIENT],
                ],
                [
                    ['text' => self::BUTTON_CLOSE_CLIENT],
                    ['text' => self::BUTTON_VIEW_SUMMARY],
                ],
                [
                    ['text' => self::BUTTON_NEW_CLIENT],
                    ['text' => self::BUTTON_MY_CLIENTS],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function startChatKeyboard(int|string|null $clientId = null): array
    {
        if ($clientId !== null && $clientId !== '') {
            return [
                'inline_keyboard' => [
                    [
                        ['text' => self::BUTTON_START_CHAT, 'callback_data' => "chat:start:{$clientId}"],
                    ],
                ],
            ];
        }

        return [
            'keyboard' => [
                [
                    ['text' => self::BUTTON_START_CHAT],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function replySuggestionsKeyboard(int|string|null $suggestionId = null, int|string|null $clientId = null): array
    {
        if ($suggestionId !== null && $suggestionId !== '') {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => self::BUTTON_SENT_OPTION_1, 'callback_data' => "sg:sel:{$suggestionId}:1"],
                    ],
                    [
                        ['text' => self::BUTTON_SENT_OPTION_2, 'callback_data' => "sg:sel:{$suggestionId}:2"],
                    ],
                    [
                        ['text' => self::BUTTON_SENT_OPTION_3, 'callback_data' => "sg:sel:{$suggestionId}:3"],
                    ],
                    [
                        ['text' => self::BUTTON_REGENERATE, 'callback_data' => "sg:rg:{$suggestionId}"],
                    ],
                    [
                        ['text' => self::BUTTON_DISLIKE_REPLIES, 'callback_data' => "sg:fb:{$suggestionId}"],
                    ],
                    [
                        ['text' => self::BUTTON_OWN_REPLY, 'callback_data' => "sg:custom:{$suggestionId}"],
                    ],
                ],
            ];

            if ($clientId !== null && $clientId !== '') {
                $keyboard['inline_keyboard'][] = [
                    ['text' => self::BUTTON_PAUSE_CLIENT_FINISH, 'callback_data' => "cl:pa:{$clientId}"],
                ];
            }

            return $keyboard;
        }

        return [
            'keyboard' => [
                [
                    ['text' => self::BUTTON_SENT_OPTION_1],
                ],
                [
                    ['text' => self::BUTTON_SENT_OPTION_2],
                ],
                [
                    ['text' => self::BUTTON_SENT_OPTION_3],
                ],
                [
                    ['text' => self::BUTTON_REGENERATE],
                ],
                [
                    ['text' => self::BUTTON_DISLIKE_REPLIES],
                ],
                [
                    ['text' => self::BUTTON_OWN_REPLY],
                ],
                [
                    ['text' => self::BUTTON_PAUSE_CLIENT_FINISH],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function highRiskWarningLines(BotSuggestion $suggestion): array
    {
        if (strtolower((string) $suggestion->risk_level) === 'high') {
            return [
                $this->html($this->languages->text('high_risk_title')),
                $this->html($this->languages->text('high_risk_body')),
                $this->html($this->languages->text('high_risk_safe_path')),
                '',
            ];
        }

        if (strtolower((string) $suggestion->risk_level) !== 'high') {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function customReplyRiskSummary(array $analysis): string
    {
        $notes = [
            $this->messages->text('custom_risk_level', [
                'risk' => $this->messages->riskLevelLabel((string) ($analysis['risk_level'] ?? 'low')),
            ]),
        ];

        if ($analysis['pricing_discussed'] ?? false) {
            $notes[] = $this->messages->text('custom_pricing');
        }

        if ($analysis['deadline_discussed'] ?? false) {
            $notes[] = $this->messages->text('custom_deadline');
        }

        if ($analysis['promises_made'] ?? false) {
            $notes[] = $this->messages->text('custom_promise');
        }

        if ($analysis['access_needed'] ?? false) {
            $notes[] = $this->messages->text('custom_access');
        }

        if (count($notes) === 1) {
            $notes[] = $this->messages->text('custom_no_risk');
        }

        return implode("\n", $notes);
    }
}
