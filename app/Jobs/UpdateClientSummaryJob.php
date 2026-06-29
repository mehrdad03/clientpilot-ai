<?php

namespace App\Jobs;

use App\Services\Ai\AiSensitiveDataMasker;
use App\Services\Clients\ClientAiProcessingLock;
use App\Services\Clients\MemorySummaryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class UpdateClientSummaryJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $clientId,
    ) {}

    public function handle(
        ClientAiProcessingLock $processingLock,
        MemorySummaryService $memorySummaryService,
        AiSensitiveDataMasker $masker,
    ): void
    {
        try {
            $run = $processingLock->run(
                $this->clientId,
                fn () => $memorySummaryService->updateForClient($this->clientId, [
                    'queue_name' => $this->queue,
                    'job_class' => self::class,
                    'job_uuid' => $this->jobUuid(),
                ])
            );

            if (! $run['acquired']) {
                Log::info('Client memory summary update skipped because AI processing is already active.', [
                    'client_id' => $this->clientId,
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Client memory summary update failed.', [
                'client_id' => $this->clientId,
                'error' => $masker->maskText($exception->getMessage()),
            ]);
        }
    }

    private function jobUuid(): ?string
    {
        if ($this->job === null || ! method_exists($this->job, 'uuid')) {
            return null;
        }

        return $this->job->uuid();
    }
}
