<?php

namespace App\Services\Ai;

use App\Enums\AiRequestStatus;
use App\Models\AiRequest;

class AiRequestLogger
{
    public function __construct(
        private readonly AiSensitiveDataMasker $masker,
    ) {}

    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>  $metadata
     */
    public function createPending(
        string $provider,
        ?string $model = null,
        ?string $promptKey = null,
        ?string $promptVersion = null,
        array $requestPayload = [],
        array $metadata = [],
    ): AiRequest {
        return AiRequest::query()->create([
            'provider' => $provider,
            'model' => $model,
            'prompt_key' => $promptKey,
            'prompt_version' => $promptVersion,
            'status' => AiRequestStatus::Pending->value,
            'request_payload' => $this->maskPayload($requestPayload),
            'metadata' => $this->maskPayload($metadata),
        ]);
    }

    public function markSent(AiRequest|int $aiRequest, ?string $queueName = null, ?string $jobClass = null, ?string $jobUuid = null): AiRequest
    {
        $aiRequest = $this->resolve($aiRequest);

        $aiRequest->update([
            'status' => AiRequestStatus::Sent->value,
            'queue_name' => $queueName,
            'job_class' => $jobClass,
            'job_uuid' => $jobUuid,
            'started_at' => now(),
        ]);

        return $aiRequest->refresh();
    }

    /**
     * @param  array<string, mixed>  $responsePayload
     */
    public function markSucceeded(AiRequest|int $aiRequest, array $responsePayload = [], ?int $durationMs = null): AiRequest
    {
        $aiRequest = $this->resolve($aiRequest);

        $aiRequest->update([
            'status' => AiRequestStatus::Succeeded->value,
            'response_payload' => $this->maskPayload($responsePayload),
            'duration_ms' => $durationMs,
            'completed_at' => now(),
        ]);

        return $aiRequest->refresh();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markFailed(AiRequest|int $aiRequest, string $errorMessage, array $metadata = [], ?int $durationMs = null): AiRequest
    {
        $aiRequest = $this->resolve($aiRequest);

        $aiRequest->update([
            'status' => AiRequestStatus::Failed->value,
            'metadata' => $this->mergeMetadata($aiRequest, $metadata),
            'duration_ms' => $durationMs,
            'failed_at' => now(),
            'error_message' => $this->masker->maskText($errorMessage),
        ]);

        return $aiRequest->refresh();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markValidationFailed(AiRequest|int $aiRequest, array $metadata = []): AiRequest
    {
        $aiRequest = $this->resolve($aiRequest);

        $aiRequest->update([
            'status' => AiRequestStatus::ValidationFailed->value,
            'metadata' => $this->mergeMetadata($aiRequest, $metadata),
            'failed_at' => now(),
        ]);

        return $aiRequest->refresh();
    }

    private function resolve(AiRequest|int $aiRequest): AiRequest
    {
        if ($aiRequest instanceof AiRequest) {
            return $aiRequest;
        }

        return AiRequest::query()->findOrFail($aiRequest);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function maskPayload(array $payload): array
    {
        return $this->masker->maskArray($payload);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function mergeMetadata(AiRequest $aiRequest, array $metadata): array
    {
        return array_merge($aiRequest->metadata ?? [], $this->maskPayload($metadata));
    }
}
