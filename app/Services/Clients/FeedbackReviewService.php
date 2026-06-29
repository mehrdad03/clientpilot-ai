<?php

namespace App\Services\Clients;

use App\Contracts\Ai\AiProviderInterface;
use App\Enums\BotSuggestionStatus;
use App\Enums\UserFeedbackDecision;
use App\Models\AiRequest;
use App\Models\BotSuggestion;
use App\Models\BotSuggestionOption;
use App\Models\UserFeedback;
use App\Services\Ai\AiJsonResponseValidator;
use App\Services\Ai\AiRequestLogger;
use App\Services\Ai\PromptBuilderService;
use App\Services\Copilot\CopilotLanguageService;
use App\Services\Telegram\TelegramMessageFormatter;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class FeedbackReviewService
{
    private const PROMPT_KEY = 'sales_copilot_feedback_review';

    private const PROMPT_VERSION = 'v1';

    private const RESULT_ACTION_REGENERATED = 'regenerated';

    private const RESULT_ACTION_KEPT_ORIGINAL = 'kept_original';

    private const RESULT_ACTION_MODIFIED = 'modified';

    private const REQUIRED_KEYS = [
        'ai_decision',
        'ai_reason',
        'result_action',
        'client_read',
        'best_move',
        'risk_level',
        'risk_reason',
        'detected_intent',
        'next_stage',
        'reply_options',
    ];

    public function __construct(
        private readonly AiProviderInterface $aiProvider,
        private readonly PromptBuilderService $promptBuilder,
        private readonly AiJsonResponseValidator $jsonValidator,
        private readonly AiRequestLogger $requestLogger,
        private readonly ConversationBrainService $conversationBrain,
        private readonly TelegramMessageFormatter $messageFormatter,
        private readonly CopilotLanguageService $languages,
    ) {}

    public function latestGeneratedSuggestion(int|string $telegramUserId, int|string|null $activeClientId): ?BotSuggestion
    {
        if ($activeClientId === null || $activeClientId === '') {
            return null;
        }

        return BotSuggestion::query()
            ->where('telegram_user_id', $telegramUserId)
            ->where('client_id', $activeClientId)
            ->where('status', BotSuggestionStatus::Generated->value)
            ->latest('id')
            ->first();
    }

    public function generatedSuggestionForUser(int|string $telegramUserId, int|string $botSuggestionId): ?BotSuggestion
    {
        return BotSuggestion::query()
            ->where('id', $botSuggestionId)
            ->where('telegram_user_id', $telegramUserId)
            ->where('status', BotSuggestionStatus::Generated->value)
            ->first();
    }

    public function storePendingFeedback(
        int|string $telegramUserId,
        int|string|null $activeClientId,
        int|string|null $botSuggestionId,
        string $feedbackText
    ): ?UserFeedback {
        if ($botSuggestionId === null || $botSuggestionId === '') {
            return null;
        }

        $suggestion = BotSuggestion::query()
            ->where('id', $botSuggestionId)
            ->where('telegram_user_id', $telegramUserId)
            ->where('client_id', $activeClientId)
            ->where('status', BotSuggestionStatus::Generated->value)
            ->first();

        if ($suggestion === null) {
            return null;
        }

        return UserFeedback::query()->create([
            'bot_suggestion_id' => $suggestion->id,
            'client_id' => $suggestion->client_id,
            'telegram_user_id' => $suggestion->telegram_user_id,
            'feedback_text' => $feedbackText,
        ]);
    }

    /**
     * @param  array<string, string|null>  $jobContext
     * @return array{feedback: UserFeedback, decision: UserFeedbackDecision, message: string, options: array<int, BotSuggestionOption>, suggestion: BotSuggestion|null, ai_request: AiRequest}
     */
    public function reviewFeedback(int|string $userFeedbackId, array $jobContext = []): array
    {
        $feedback = UserFeedback::query()->find($userFeedbackId);

        if ($feedback === null) {
            throw new RuntimeException("User feedback [{$userFeedbackId}] was not found.");
        }

        $context = $this->conversationBrain->contextForSuggestion($feedback->bot_suggestion_id);
        $client = $context['client'];
        $suggestion = $context['suggestion'];
        $conversationText = $this->conversationBrain->formatConversation($context['conversation'], $context['summary']);
        $originalOptions = $this->formatOriginalOptions($feedback->bot_suggestion_id);

        $prompt = $this->promptBuilder->build(self::PROMPT_KEY, self::PROMPT_VERSION, [
            'client_title' => $client['title'] ?? '',
            'client_type' => $client['client_type'] ?? '',
            'personality_type' => $client['personality_type'] ?? '',
            'main_need' => $client['main_need'] ?? '',
            'best_strategy' => $client['best_strategy'] ?? '',
            'risk_level' => $client['risk_level'] ?? '',
            'client_summary' => $client['client_summary'] ?? '',
            'conversation_stage' => $client['stage'] ?? '',
            'conversation_history' => $conversationText,
            'original_client_read' => $suggestion['client_read'] ?? '',
            'original_best_move' => $suggestion['best_move'] ?? '',
            'original_risk_level' => $suggestion['risk_level'] ?? '',
            'original_risk_reason' => $suggestion['risk_reason'] ?? '',
            'original_options' => $originalOptions,
            'feedback_text' => $feedback->feedback_text,
            'native_language' => $this->languages->nativeLanguage(),
            'target_language' => $this->languages->targetLanguage(),
            'native_language_name' => $this->languages->languageName($this->languages->nativeLanguage()),
            'target_language_name' => $this->languages->languageName($this->languages->targetLanguage()),
            'bilingual_output' => $this->languages->isBilingual() ? 'yes' : 'no',
            'target_platform_name' => $this->languages->targetPlatformName(),
        ]);

        $model = (string) config('ai.providers.openai.model');
        $aiRequest = $this->requestLogger->createPending(
            provider: (string) config('ai.default_provider', 'openai'),
            model: $model,
            promptKey: self::PROMPT_KEY,
            promptVersion: self::PROMPT_VERSION,
            requestPayload: [
                'user_feedback_id' => $feedback->id,
                'bot_suggestion_id' => $feedback->bot_suggestion_id,
                'client_id' => $feedback->client_id,
                'input' => $prompt,
            ],
            metadata: [
                'telegram_user_id' => $feedback->telegram_user_id,
            ],
        );

        $startedAt = microtime(true);

        try {
            $this->requestLogger->markSent(
                $aiRequest,
                $jobContext['queue_name'] ?? null,
                $jobContext['job_class'] ?? null,
                $jobContext['job_uuid'] ?? null
            );

            $response = $this->aiProvider->createResponse($prompt, [
                'model' => $model,
            ]);

            $rawText = $this->extractTextFromResponse($response);
            $validation = $this->jsonValidator->validate($rawText, self::REQUIRED_KEYS);
            $durationMs = $this->durationMs($startedAt);

            if (! $validation['valid'] || $validation['data'] === null) {
                $this->requestLogger->markValidationFailed($aiRequest, [
                    'errors' => $validation['errors'],
                    'raw_output' => $rawText,
                ]);

                throw new RuntimeException('AI feedback review response failed validation.');
            }

            $decision = $this->normalizeDecision($validation['data']['ai_decision']);
            $resultAction = $this->normalizeResultAction($decision, $validation['data']['result_action']);
            $aiReason = $this->stringValue($validation['data']['ai_reason']);

            if ($decision === UserFeedbackDecision::Rejected) {
                $feedback = $this->storeRejectedFeedback($feedback, $aiRequest, $decision, $aiReason, $resultAction);
                $aiRequest = $this->requestLogger->markSucceeded($aiRequest, $response, $durationMs);

                return [
                    'feedback' => $feedback,
                    'decision' => $decision,
                    'message' => $this->messageFormatter->feedbackRejectedMessage($aiReason),
                    'options' => [],
                    'suggestion' => null,
                    'ai_request' => $aiRequest,
                ];
            }

            $suggestionData = $this->normalizeSuggestion($validation['data']);
            $options = $this->normalizeOptions($validation['data']['reply_options']);

            [$feedback, $newSuggestion, $optionModels] = DB::transaction(function () use ($feedback, $aiRequest, $decision, $aiReason, $resultAction, $suggestionData, $options): array {
                $newSuggestion = BotSuggestion::query()->create([
                    'client_id' => $feedback->client_id,
                    'conversation_message_id' => BotSuggestion::query()->where('id', $feedback->bot_suggestion_id)->value('conversation_message_id'),
                    'ai_request_id' => $aiRequest->id,
                    'telegram_user_id' => $feedback->telegram_user_id,
                    'client_read' => $suggestionData['client_read'],
                    'best_move' => $suggestionData['best_move'],
                    'risk_level' => $suggestionData['risk_level'],
                    'risk_reason' => $suggestionData['risk_reason'],
                    'detected_intent' => $suggestionData['detected_intent'],
                    'next_stage' => $suggestionData['next_stage'],
                    'status' => BotSuggestionStatus::Generated->value,
                ]);

                $optionModels = [];

                foreach ($options as $option) {
                    $optionModels[] = BotSuggestionOption::query()->create([
                        'bot_suggestion_id' => $newSuggestion->id,
                        'option_number' => $option['option_number'],
                        'label' => $option['label'],
                        'type' => $option['type'],
                        'body' => $option['body'],
                        'native_meaning' => $option['native_meaning'],
                    ]);
                }

                BotSuggestion::query()
                    ->where('id', $feedback->bot_suggestion_id)
                    ->update([
                        'status' => BotSuggestionStatus::Regenerated->value,
                        'updated_at' => now(),
                    ]);

                $feedback->update([
                    'replacement_bot_suggestion_id' => $newSuggestion->id,
                    'ai_request_id' => $aiRequest->id,
                    'ai_decision' => $decision->value,
                    'ai_reason' => $aiReason,
                    'result_action' => $resultAction,
                ]);

                return [$feedback->refresh(), $newSuggestion->refresh(), $optionModels];
            });

            $aiRequest = $this->requestLogger->markSucceeded($aiRequest, $response, $durationMs);

            return [
                'feedback' => $feedback,
                'decision' => $decision,
                'message' => $this->messageFormatter->replySuggestionsMessage($newSuggestion, $optionModels),
                'options' => $optionModels,
                'suggestion' => $newSuggestion,
                'ai_request' => $aiRequest,
            ];
        } catch (Throwable $exception) {
            if ($aiRequest->fresh()?->failed_at === null) {
                $this->requestLogger->markFailed($aiRequest, $exception->getMessage(), [], $this->durationMs($startedAt));
            }

            throw $exception;
        }
    }

    private function storeRejectedFeedback(
        UserFeedback $feedback,
        AiRequest $aiRequest,
        UserFeedbackDecision $decision,
        string $aiReason,
        string $resultAction
    ): UserFeedback {
        $feedback->update([
            'ai_request_id' => $aiRequest->id,
            'ai_decision' => $decision->value,
            'ai_reason' => $aiReason,
            'result_action' => $resultAction,
        ]);

        return $feedback->refresh();
    }

    private function formatOriginalOptions(int|string $botSuggestionId): string
    {
        return BotSuggestionOption::query()
            ->where('bot_suggestion_id', $botSuggestionId)
            ->orderBy('option_number')
            ->get()
            ->map(function (BotSuggestionOption $option): string {
                $parts = [
                    "Option {$option->option_number} - {$option->label}",
                    'Target text: '.$option->body,
                ];

                if ($option->native_meaning !== null && $option->native_meaning !== '') {
                    $parts[] = 'Native meaning: '.$option->native_meaning;
                }

                return implode("\n", $parts);
            })
            ->implode("\n\n");
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractTextFromResponse(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }

        $text = data_get($response, 'output.0.content.0.text')
            ?? data_get($response, 'output.0.content.0.content')
            ?? data_get($response, 'choices.0.message.content');

        if (is_string($text)) {
            return $text;
        }

        throw new RuntimeException('AI response did not contain text output.');
    }

    private function normalizeDecision(mixed $value): UserFeedbackDecision
    {
        $decision = UserFeedbackDecision::tryFrom($this->stringValue($value));

        if ($decision === null) {
            throw new RuntimeException('AI feedback review decision is invalid.');
        }

        return $decision;
    }

    private function normalizeResultAction(UserFeedbackDecision $decision, mixed $value): string
    {
        $action = $this->stringValue($value);
        $validActions = [
            self::RESULT_ACTION_REGENERATED,
            self::RESULT_ACTION_KEPT_ORIGINAL,
            self::RESULT_ACTION_MODIFIED,
        ];

        if (! in_array($action, $validActions, true)) {
            throw new RuntimeException('AI feedback review result_action is invalid.');
        }

        if ($decision === UserFeedbackDecision::Rejected && $action !== self::RESULT_ACTION_KEPT_ORIGINAL) {
            throw new RuntimeException('Rejected feedback must keep original options.');
        }

        if ($decision !== UserFeedbackDecision::Rejected && $action === self::RESULT_ACTION_KEPT_ORIGINAL) {
            throw new RuntimeException('Accepted feedback must create modified or regenerated options.');
        }

        return $action;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function normalizeSuggestion(array $data): array
    {
        return [
            'client_read' => $this->stringValue($data['client_read'] ?? ''),
            'best_move' => $this->stringValue($data['best_move'] ?? ''),
            'risk_level' => $this->stringValue($data['risk_level'] ?? ''),
            'risk_reason' => $this->stringValue($data['risk_reason'] ?? ''),
            'detected_intent' => $this->stringValue($data['detected_intent'] ?? ''),
            'next_stage' => $this->stringValue($data['next_stage'] ?? ''),
        ];
    }

    /**
     * @return array<int, array{option_number: int, label: string, type: string, body: string, native_meaning: string|null}>
     */
    private function normalizeOptions(mixed $replyOptions): array
    {
        if (! is_array($replyOptions) || count($replyOptions) !== 3) {
            throw new RuntimeException('Accepted feedback must include exactly 3 reply options.');
        }

        $defaults = [
            1 => 'Short',
            2 => 'Professional',
            3 => 'Closing / Sales-focused',
        ];

        $options = [];

        foreach (array_values($replyOptions) as $index => $option) {
            if (! is_array($option)) {
                throw new RuntimeException('Each feedback reply option must be an object.');
            }

            $optionNumber = $index + 1;
            $fallbackLabel = $this->stringValue($option['label'] ?? $defaults[$optionNumber]);
            $type = $this->languages->optionTypeFromValue($option['type'] ?? null, $optionNumber, $fallbackLabel);
            $body = $this->stringValue($option['target_text'] ?? $option['body'] ?? $option['text'] ?? '');
            $nativeMeaning = $this->languages->isBilingual()
                ? $this->stringValue($option['native_meaning'] ?? '')
                : '';

            if ($body === '') {
                throw new RuntimeException("Feedback reply option [{$optionNumber}] is empty.");
            }

            $options[] = [
                'option_number' => $optionNumber,
                'label' => $this->languages->optionTypeLabel($type, $optionNumber, $fallbackLabel),
                'type' => $type,
                'body' => $body,
                'native_meaning' => $nativeMeaning !== '' ? $nativeMeaning : null,
            ];
        }

        return $options;
    }

    private function stringValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
