<?php

namespace App\Services\Clients;

use App\Contracts\Ai\AiProviderInterface;
use App\Enums\ClientStage;
use App\Enums\ConversationMessageType;
use App\Enums\ConversationSender;
use App\Models\AiRequest;
use App\Services\Ai\AiJsonResponseValidator;
use App\Services\Ai\AiRequestLogger;
use App\Services\Ai\PromptBuilderService;
use App\Services\Copilot\CopilotLanguageService;
use App\Services\Telegram\TelegramMessageFormatter;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ClientAnalysisService
{
    private const PROMPT_KEY = 'sales_copilot_analysis';

    private const PROMPT_VERSION = 'v1';

    private const REQUIRED_KEYS = [
        'title',
        'client_type',
        'personality_type',
        'main_need',
        'best_strategy',
        'risk_level',
        'client_summary',
        'best_angle_for_mehrdad',
        'risks',
    ];

    public function __construct(
        private readonly AiProviderInterface $aiProvider,
        private readonly PromptBuilderService $promptBuilder,
        private readonly AiJsonResponseValidator $jsonValidator,
        private readonly AiRequestLogger $requestLogger,
        private readonly TelegramMessageFormatter $messageFormatter,
        private readonly CopilotLanguageService $languages,
    ) {}

    /**
     * @param  array<string, string|null>  $jobContext
     * @return array{client: array<string, mixed>, analysis: array<string, mixed>, message: string, ai_request: AiRequest}
     */
    public function analyzeInitialClient(int|string $clientId, array $jobContext = []): array
    {
        $client = $this->loadClient($clientId);
        $initialMessage = $this->loadInitialMessage($client['id']);
        $prompt = $this->promptBuilder->build(self::PROMPT_KEY, self::PROMPT_VERSION, [
            'initial_job_text' => $initialMessage,
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
                'input' => $prompt,
            ],
            metadata: [
                'telegram_user_id' => $client['telegram_user_id'],
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

            $analysisText = $this->extractTextFromResponse($response);
            $validation = $this->jsonValidator->validate($analysisText, self::REQUIRED_KEYS);
            $durationMs = $this->durationMs($startedAt);

            if (! $validation['valid'] || $validation['data'] === null) {
                $this->requestLogger->markValidationFailed($aiRequest, [
                    'errors' => $validation['errors'],
                    'raw_output' => $analysisText,
                ]);

                throw new RuntimeException('AI analysis response failed validation.');
            }

            $analysis = $this->normalizeAnalysis($validation['data']);
            $message = $this->messageFormatter->clientAnalysisMessage($analysis);

            DB::transaction(function () use ($client, $analysis, $message): void {
                DB::table('clients')
                    ->where('id', $client['id'])
                    ->update([
                        'title' => $analysis['title'],
                        'client_type' => $analysis['client_type'],
                        'personality_type' => $analysis['personality_type'],
                        'main_need' => $analysis['main_need'],
                        'best_strategy' => $analysis['best_strategy'],
                        'risk_level' => $analysis['risk_level'],
                        'client_summary' => $analysis['client_summary'],
                        'stage' => ClientStage::Analyzed->value,
                        'updated_at' => now(),
                    ]);

                DB::table('conversation_messages')->insert([
                    'client_id' => $client['id'],
                    'telegram_user_id' => $client['telegram_user_id'],
                    'sender' => ConversationSender::Bot->value,
                    'message_type' => ConversationMessageType::BotAnalysis->value,
                    'body' => $message,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $aiRequest = $this->requestLogger->markSucceeded($aiRequest, $response, $durationMs);

            return [
                'client' => $this->loadClient($client['id']),
                'analysis' => $analysis,
                'message' => $message,
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
     * @return array<string, mixed>
     */
    private function loadClient(int|string $clientId): array
    {
        $client = DB::table('clients')->where('id', $clientId)->first();

        if ($client === null) {
            throw new RuntimeException("Client [{$clientId}] was not found.");
        }

        return (array) $client;
    }

    private function loadInitialMessage(int|string $clientId): string
    {
        $message = DB::table('conversation_messages')
            ->where('client_id', $clientId)
            ->where('message_type', ConversationMessageType::InitialJob->value)
            ->orderBy('id')
            ->value('body');

        if (! is_string($message) || trim($message) === '') {
            throw new RuntimeException("Initial job message for client [{$clientId}] was not found.");
        }

        return $message;
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
     * @param  array<string, mixed>  $analysis
     * @return array<string, string>
     */
    private function normalizeAnalysis(array $analysis): array
    {
        $normalized = [];
        $localizedFields = [
            'client_type',
            'personality_type',
            'main_need',
            'best_strategy',
            'client_summary',
            'best_angle_for_mehrdad',
            'risks',
        ];

        foreach (self::REQUIRED_KEYS as $key) {
            $value = $analysis[$key] ?? '';

            if (in_array($key, $localizedFields, true)) {
                [$nativeText, $targetText] = $this->localizedValue($value);
                $normalized[$key] = $nativeText;

                if ($targetText !== '') {
                    $normalized[$key.'_target'] = $targetText;
                }

                continue;
            }

            $normalized[$key] = $this->stringValue($value);
        }

        return $normalized;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function localizedValue(mixed $value): array
    {
        if (! is_array($value)) {
            return [$this->stringValue($value), ''];
        }

        $nativeText = $this->firstString($value, [
            'native_text',
            'native',
            $this->languages->nativeLanguage(),
            $this->languages->languageName($this->languages->nativeLanguage()),
            'text',
        ]);

        $targetText = $this->firstString($value, [
            'target_text',
            'target',
            $this->languages->targetLanguage(),
            $this->languages->languageName($this->languages->targetLanguage()),
        ]);

        if ($nativeText === '' && $targetText !== '') {
            $nativeText = $targetText;
            $targetText = '';
        }

        if ($targetText === $nativeText) {
            $targetText = '';
        }

        return [$nativeText, $targetText];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $keys
     */
    private function firstString(array $values, array $keys): string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $this->stringValue($values[$key]);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
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
