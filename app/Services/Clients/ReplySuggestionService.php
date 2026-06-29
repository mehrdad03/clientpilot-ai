<?php

namespace App\Services\Clients;

use App\Contracts\Ai\AiProviderInterface;
use App\Enums\BotSuggestionStatus;
use App\Enums\ClientStage;
use App\Models\AiRequest;
use App\Models\BotSuggestion;
use App\Models\BotSuggestionOption;
use App\Services\Ai\AiJsonResponseValidator;
use App\Services\Ai\AiRequestLogger;
use App\Services\Ai\PromptBuilderService;
use App\Services\Copilot\CopilotLanguageService;
use App\Services\Telegram\TelegramMessageFormatter;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ReplySuggestionService
{
    private const PROMPT_KEY = 'sales_copilot_reply';

    private const PROMPT_VERSION = 'v1';

    private const REQUIRED_KEYS = [
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
        private readonly RiskGuardService $riskGuardService,
        private readonly CopilotLanguageService $languages,
    ) {}

    /**
     * @param  array<string, string|null>  $jobContext
     * @return array{suggestion: BotSuggestion, options: array<int, BotSuggestionOption>, message: string, ai_request: AiRequest}
     */
    public function generateForClientMessage(int|string $clientId, int|string $conversationMessageId, array $jobContext = []): array
    {
        $context = $this->conversationBrain->contextForReplySuggestions($clientId, $conversationMessageId);
        $client = $context['client'];
        $latestMessage = $context['latest_client_message'];
        $conversationText = $this->conversationBrain->formatConversation($context['conversation'], $context['summary']);
        $riskAssessment = $this->riskGuardService->assess($client, $latestMessage, $context['summary']);

        $prompt = $this->promptBuilder->build(self::PROMPT_KEY, self::PROMPT_VERSION, [
            'client_title' => $client['title'] ?? '',
            'client_type' => $client['client_type'] ?? '',
            'personality_type' => $client['personality_type'] ?? '',
            'main_need' => $client['main_need'] ?? '',
            'best_strategy' => $client['best_strategy'] ?? '',
            'risk_level' => $client['risk_level'] ?? '',
            'client_summary' => $client['client_summary'] ?? '',
            'latest_client_message' => $latestMessage['body'],
            'conversation_history' => $conversationText,
            'risk_guard_policy' => $riskAssessment['policy_prompt'],
            'risk_guard_context' => $riskAssessment['prompt_context'],
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
                'client_id' => $client['id'],
                'conversation_message_id' => $latestMessage['id'],
                'input' => $prompt,
            ],
            metadata: [
                'telegram_user_id' => $client['telegram_user_id'],
                'risk_guard' => [
                    'risk_level' => $riskAssessment['risk_level'],
                    'flags' => $riskAssessment['flags'],
                    'closing_mode' => $riskAssessment['closing_mode'],
                ],
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

                throw new RuntimeException('AI reply suggestion response failed validation.');
            }

            $suggestionData = $this->normalizeSuggestion($validation['data']);
            $options = $this->normalizeOptions($validation['data']['reply_options']);
            $guardedSuggestion = $this->riskGuardService->guardSuggestion($suggestionData, $options, $riskAssessment);
            $suggestionData = $guardedSuggestion['suggestion'];
            $options = $guardedSuggestion['options'];

            [$suggestion, $optionModels] = DB::transaction(function () use ($client, $latestMessage, $aiRequest, $suggestionData, $options): array {
                $suggestion = BotSuggestion::query()->create([
                    'client_id' => $client['id'],
                    'conversation_message_id' => $latestMessage['id'],
                    'ai_request_id' => $aiRequest->id,
                    'telegram_user_id' => $client['telegram_user_id'],
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
                        'bot_suggestion_id' => $suggestion->id,
                        'option_number' => $option['option_number'],
                        'label' => $option['label'],
                        'type' => $option['type'],
                        'body' => $option['body'],
                        'native_meaning' => $option['native_meaning'],
                    ]);
                }

                if ($this->isValidNextStage($suggestionData['next_stage'])) {
                    DB::table('clients')
                        ->where('id', $client['id'])
                        ->update([
                            'stage' => $suggestionData['next_stage'],
                            'updated_at' => now(),
                        ]);
                }

                return [$suggestion->refresh(), $optionModels];
            });

            $aiRequest = $this->requestLogger->markSucceeded($aiRequest, $response, $durationMs);

            return [
                'suggestion' => $suggestion,
                'options' => $optionModels,
                'message' => $this->messageFormatter->replySuggestionsMessage($suggestion, $optionModels),
                'ai_request' => $aiRequest,
            ];
        } catch (Throwable $exception) {
            if ($aiRequest->fresh()?->failed_at === null) {
                $this->requestLogger->markFailed($aiRequest, $exception->getMessage(), [], $this->durationMs($startedAt));
            }

            throw $exception;
        }
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
            throw new RuntimeException('AI reply suggestion response must include exactly 3 reply options.');
        }

        $defaults = [
            1 => 'Short',
            2 => 'Professional',
            3 => 'Closing / Sales-focused',
        ];

        $options = [];

        foreach (array_values($replyOptions) as $index => $option) {
            if (! is_array($option)) {
                throw new RuntimeException('Each reply option must be an object.');
            }

            $optionNumber = $index + 1;
            $fallbackLabel = $this->stringValue($option['label'] ?? $defaults[$optionNumber]);
            $type = $this->languages->optionTypeFromValue($option['type'] ?? null, $optionNumber, $fallbackLabel);
            $body = $this->stringValue($option['target_text'] ?? $option['body'] ?? $option['text'] ?? '');
            $nativeMeaning = $this->languages->isBilingual()
                ? $this->stringValue($option['native_meaning'] ?? '')
                : '';

            if ($body === '') {
                throw new RuntimeException("Reply option [{$optionNumber}] is empty.");
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

    private function isValidNextStage(string $stage): bool
    {
        return in_array($stage, array_map(
            static fn (ClientStage $clientStage): string => $clientStage->value,
            ClientStage::cases()
        ), true);
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
