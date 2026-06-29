<?php

namespace App\Services\Clients;

use App\Services\Ai\PromptBuilderService;
use App\Services\Copilot\CopilotLanguageService;

class RiskGuardService
{
    private const PROMPT_KEY = 'risk_guard';

    private const PROMPT_VERSION = 'v1';

    public function __construct(
        private readonly MarketplaceSafetyPolicyService $safetyPolicy,
        private readonly PromptBuilderService $promptBuilder,
        private readonly CopilotLanguageService $languages,
    ) {}

    /**
     * @param  array<string, mixed>  $client
     * @param  array<string, mixed>  $latestMessage
     * @return array<string, mixed>
     */
    public function assess(array $client, array $latestMessage, ?object $summary = null): array
    {
        $policyResult = $this->safetyPolicy->detect((string) ($latestMessage['body'] ?? ''), $summary);
        $closingMode = $this->safetyPolicy->seemsReadyToClose((string) ($latestMessage['body'] ?? ''));
        $riskReason = $this->riskReason($policyResult['flags']);

        $assessment = [
            'risk_level' => $policyResult['risk_level'],
            'risk_reason' => $riskReason,
            'flags' => $policyResult['flags'],
            'is_high_risk' => $policyResult['risk_level'] === 'high',
            'closing_mode' => $closingMode,
            'closing_note' => $closingMode
                ? 'Client may be ready to proceed; guide toward clear scope and a funded '.$this->languages->targetPlatformName().' milestone before starting.'
                : 'Client does not appear ready for contract closing yet.',
        ];

        $assessment['policy_prompt'] = $this->policyPrompt($client, $latestMessage, $assessment);
        $assessment['prompt_context'] = $this->promptContext($assessment);

        return $assessment;
    }

    /**
     * @param  array<string, string>  $suggestion
     * @param  array<int, array{option_number: int, label: string, type: string, body: string, native_meaning: string|null}>  $options
     * @param  array<string, mixed>  $assessment
     * @return array{suggestion: array<string, string>, options: array<int, array{option_number: int, label: string, type: string, body: string, native_meaning: string|null}>}
     */
    public function guardSuggestion(array $suggestion, array $options, array $assessment): array
    {
        $unsafeAiOutput = $this->containsUnsafeOutput($suggestion, $options);
        $highRisk = ($assessment['is_high_risk'] ?? false) || $unsafeAiOutput;

        if ($highRisk) {
            $suggestion['risk_level'] = 'high';
            $suggestion['risk_reason'] = $this->highRiskReason($assessment, $unsafeAiOutput);
            $suggestion['best_move'] = $this->languages->text('safe_best_move');
            $options = $this->safeReplyOptions();
        } elseif ($assessment['closing_mode'] ?? false) {
            $suggestion['best_move'] = $this->closingBestMove($suggestion['best_move'] ?? '');
        }

        return [
            'suggestion' => $suggestion,
            'options' => $options,
        ];
    }

    /**
     * @param  array<string, mixed>  $client
     * @param  array<string, mixed>  $latestMessage
     * @param  array<string, mixed>  $assessment
     */
    private function policyPrompt(array $client, array $latestMessage, array $assessment): string
    {
        return $this->promptBuilder->build(self::PROMPT_KEY, self::PROMPT_VERSION, [
            'client_title' => $client['title'] ?? '',
            'latest_client_message' => $latestMessage['body'] ?? '',
            'detected_risk_level' => $assessment['risk_level'] ?? 'low',
            'detected_risk_flags' => $this->formatFlags($assessment['flags'] ?? []),
            'detected_risk_reason' => $assessment['risk_reason'] ?? 'No high-risk signal detected.',
            'closing_mode' => ($assessment['closing_mode'] ?? false) ? 'yes' : 'no',
            'closing_note' => $assessment['closing_note'] ?? '',
            'target_platform_name' => $this->languages->targetPlatformName(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $assessment
     */
    private function promptContext(array $assessment): string
    {
        return implode("\n", [
            'Detected risk level: '.($assessment['risk_level'] ?? 'low'),
            'Detected risk reason: '.($assessment['risk_reason'] ?? 'No high-risk signal detected.'),
            'Detected risk flags: '.$this->formatFlags($assessment['flags'] ?? []),
            'Contract closing mode: '.(($assessment['closing_mode'] ?? false) ? 'yes' : 'no'),
            'Closing note: '.($assessment['closing_note'] ?? ''),
            'If risk is high, reply options must keep communication and payment on '.$this->languages->targetPlatformName().', avoid unsafe promises, clarify scope, and require a funded milestone before starting.',
        ]);
    }

    /**
     * @param  array<int, array{key: string, label: string, reason: string}>  $flags
     */
    private function riskReason(array $flags): string
    {
        if ($flags === []) {
            return 'No high-risk '.$this->languages->targetPlatformName().' safety signal detected.';
        }

        return collect($flags)
            ->map(fn (array $flag): string => "{$flag['label']}: {$flag['reason']}")
            ->implode(' | ');
    }

    /**
     * @param  array<int, array{key: string, label: string, reason: string}>  $flags
     */
    private function formatFlags(array $flags): string
    {
        if ($flags === []) {
            return 'none';
        }

        return collect($flags)
            ->map(fn (array $flag): string => $flag['key'])
            ->implode(', ');
    }

    /**
     * @param  array<string, string>  $suggestion
     * @param  array<int, array{option_number: int, label: string, type: string, body: string, native_meaning: string|null}>  $options
     */
    private function containsUnsafeOutput(array $suggestion, array $options): bool
    {
        $text = implode("\n", array_filter([
            $suggestion['client_read'] ?? '',
            $suggestion['best_move'] ?? '',
            $suggestion['risk_reason'] ?? '',
            ...array_map(static fn (array $option): string => $option['body'], $options),
        ]));

        return $this->safetyPolicy->containsUnsafeTerms($text);
    }

    /**
     * @param  array<string, mixed>  $assessment
     */
    private function highRiskReason(array $assessment, bool $unsafeAiOutput): string
    {
        if ($this->languages->nativeLanguage() === 'fa') {
            $reason = $this->languages->text('safe_risk_reason');

            if ($unsafeAiOutput) {
                $reason .= $this->languages->text('safe_unsafe_ai_output_suffix');
            }

            return $reason;
        }

        $reason = (string) ($assessment['risk_reason'] ?? '');
        $platform = $this->languages->targetPlatformName();

        if ($reason === '' || str_starts_with($reason, 'No high-risk ')) {
            $reason = $platform.' safety risk detected in reply generation.';
        } elseif (! str_contains(strtolower($reason), 'safety')) {
            $reason = $platform.' safety: '.$reason;
        }

        if ($unsafeAiOutput) {
            $reason .= $this->languages->text('safe_unsafe_ai_output_suffix');
        }

        return $reason;
    }

    private function closingBestMove(string $bestMove): string
    {
        if (str_contains(strtolower($bestMove), 'funded milestone')) {
            return $bestMove;
        }

        return trim($bestMove.' '.$this->languages->text('closing_best_move'));
    }

    /**
     * @return array<int, array{option_number: int, label: string, type: string, body: string, native_meaning: string|null}>
     */
    private function safeReplyOptions(): array
    {
        $targetTexts = $this->safeTargetTexts();
        $nativeMeanings = $this->safeNativeMeanings();

        return [
            [
                'option_number' => 1,
                'label' => 'Short',
                'type' => 'short',
                'body' => $targetTexts[0],
                'native_meaning' => $this->languages->isBilingual() ? $nativeMeanings[0] : null,
            ],
            [
                'option_number' => 2,
                'label' => 'Professional',
                'type' => 'professional',
                'body' => $targetTexts[1],
                'native_meaning' => $this->languages->isBilingual() ? $nativeMeanings[1] : null,
            ],
            [
                'option_number' => 3,
                'label' => 'Closing / Sales-focused',
                'type' => 'closing',
                'body' => $targetTexts[2],
                'native_meaning' => $this->languages->isBilingual() ? $nativeMeanings[2] : null,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function safeTargetTexts(): array
    {
        $platform = $this->languages->targetPlatformName();

        if ($this->languages->targetLanguage() === 'fa') {
            return [
                "می‌توانم کمک کنم، اما بهتر است همه چیز داخل {$platform} بماند. لطفاً scope دقیق را همین‌جا تأیید کنید تا بعد از funded شدن milestone شروع کنم.",
                "خوشحال می‌شوم جلو برویم. برای اینکه مسیر داخل {$platform} امن و شفاف باشد، اول scope، deliverableها و timeline را همین‌جا مشخص کنیم. بعد از funded شدن milestone می‌توانم شروع کنم.",
                "این پروژه مناسب به نظر می‌رسد. امن‌ترین قدم بعدی این است که اولین milestone شفاف را داخل {$platform} توافق و fund کنیم، بعد من با یک برنامه متمرکز شروع می‌کنم.",
            ];
        }

        return [
            "I can help, but let's keep everything on {$platform}. Please confirm the exact scope here, and I can start once the milestone is funded.",
            "Happy to move forward. To keep this safe and clear on {$platform}, let's define the scope, deliverables, and timeline here first. Once the milestone is funded, I can begin.",
            "This sounds like a good fit. The safest next step is to agree on the first clear milestone inside {$platform}, fund it, and then I can start with a focused delivery plan.",
        ];
    }

    /**
     * @return array<int, string>
     */
    private function safeNativeMeanings(): array
    {
        $platform = $this->languages->targetPlatformName();

        if ($this->languages->nativeLanguage() === 'fa') {
            return [
                "می‌توانم کمک کنم، اما بهتر است همه چیز داخل {$platform} بماند. لطفاً scope دقیق را همین‌جا تأیید کنید تا بعد از funded شدن milestone شروع کنم.",
                "خوشحال می‌شوم جلو برویم. برای اینکه مسیر داخل {$platform} امن و شفاف باشد، اول scope، deliverableها و timeline را همین‌جا مشخص کنیم. بعد از funded شدن milestone می‌توانم شروع کنم.",
                "این پروژه مناسب به نظر می‌رسد. امن‌ترین قدم بعدی این است که اولین milestone شفاف را داخل {$platform} توافق و fund کنیم، بعد من با یک برنامه متمرکز شروع می‌کنم.",
            ];
        }

        return [
            "I can help, but let's keep everything on {$platform}. Please confirm the exact scope here, and I can start once the milestone is funded.",
            "Happy to move forward. To keep this safe and clear on {$platform}, let's define the scope, deliverables, and timeline here first. Once the milestone is funded, I can begin.",
            "This sounds like a good fit. The safest next step is to agree on the first clear milestone inside {$platform}, fund it, and then I can start with a focused delivery plan.",
        ];
    }
}
