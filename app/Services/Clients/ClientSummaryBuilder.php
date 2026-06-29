<?php

namespace App\Services\Clients;

use App\Contracts\Ai\AiProviderInterface;
use App\Models\AiRequest;
use App\Services\Ai\AiJsonResponseValidator;
use App\Services\Ai\AiRequestLogger;
use App\Services\Ai\PromptBuilderService;
use App\Services\Copilot\CopilotLanguageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ClientSummaryBuilder
{
    private const PROMPT_KEY = 'sales_copilot_summary';

    private const PROMPT_VERSION = 'v1';

    private const RECENT_MESSAGE_LIMIT = 30;

    private const REQUIRED_KEYS = [
        'summary',
        'current_context',
        'what_client_wants',
        'what_mehrdad_promised',
        'pricing_discussed',
        'deadline_discussed',
        'access_needed',
        'open_questions',
        'risk_notes',
        'next_best_move',
    ];

    public function __construct(
        private readonly AiProviderInterface $aiProvider,
        private readonly PromptBuilderService $promptBuilder,
        private readonly AiJsonResponseValidator $jsonValidator,
        private readonly AiRequestLogger $requestLogger,
        private readonly CopilotLanguageService $languages,
    ) {}

    /**
     * @param  array<string, string|null>  $jobContext
     * @return array{summary: array<string, string>, last_message_id: int|null, ai_request: AiRequest}
     */
    public function buildForClient(int|string $clientId, array $jobContext = []): array
    {
        $client = $this->loadClient($clientId);
        $existingSummary = $this->loadExistingSummary($client['id']);
        $messages = $this->recentMessagesForClient($client['id']);
        $lastMessageId = $messages->max('id');

        $prompt = $this->promptBuilder->build(self::PROMPT_KEY, self::PROMPT_VERSION, [
            'client_title' => $client['title'] ?? '',
            'client_type' => $client['client_type'] ?? '',
            'personality_type' => $client['personality_type'] ?? '',
            'main_need' => $client['main_need'] ?? '',
            'best_strategy' => $client['best_strategy'] ?? '',
            'risk_level' => $client['risk_level'] ?? '',
            'client_summary' => $client['client_summary'] ?? '',
            'existing_memory_summary' => $this->formatExistingSummary($existingSummary),
            'recent_messages' => $this->formatMessages($messages),
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
                'last_message_id' => $lastMessageId,
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

                throw new RuntimeException('AI memory summary response failed validation.');
            }

            $summary = $this->normalizeSummary($validation['data']);
            $aiRequest = $this->requestLogger->markSucceeded($aiRequest, $response, $durationMs);

            return [
                'summary' => $summary,
                'last_message_id' => $lastMessageId === null ? null : (int) $lastMessageId,
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

    private function loadExistingSummary(int|string $clientId): ?object
    {
        return DB::table('client_summaries')->where('client_id', $clientId)->first();
    }

    /**
     * @return Collection<int, object>
     */
    private function recentMessagesForClient(int|string $clientId): Collection
    {
        return DB::table('conversation_messages')
            ->where('client_id', $clientId)
            ->latest('id')
            ->limit(self::RECENT_MESSAGE_LIMIT)
            ->get()
            ->reverse()
            ->values();
    }

    private function formatExistingSummary(?object $summary): string
    {
        if ($summary === null) {
            return 'No existing memory summary yet.';
        }

        return implode("\n", [
            'Summary: '.$summary->summary,
            'Current context: '.$summary->current_context,
            'What client wants: '.$summary->what_client_wants,
            'What Mehrdad promised: '.$summary->what_mehrdad_promised,
            'Pricing discussed: '.$summary->pricing_discussed,
            'Deadline discussed: '.$summary->deadline_discussed,
            'Access needed: '.$summary->access_needed,
            'Open questions: '.$summary->open_questions,
            'Risk notes: '.$summary->risk_notes,
            'Next best move: '.$summary->next_best_move,
            'Last message ID: '.$summary->last_message_id,
        ]);
    }

    /**
     * @param  Collection<int, object>  $messages
     */
    private function formatMessages(Collection $messages): string
    {
        if ($messages->isEmpty()) {
            return 'No conversation messages yet.';
        }

        return $messages
            ->map(fn (object $message): string => "[#{$message->id} {$message->sender}:{$message->message_type}] {$message->body}")
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function normalizeSummary(array $data): array
    {
        $normalized = [];

        foreach (self::REQUIRED_KEYS as $key) {
            $normalized[$key] = $this->stringValue($data[$key] ?? '');
        }

        return $normalized;
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
