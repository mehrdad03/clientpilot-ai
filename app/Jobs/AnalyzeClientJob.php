<?php

namespace App\Jobs;

use App\Services\Ai\AiSensitiveDataMasker;
use App\Services\Clients\ClientAiProcessingLock;
use App\Services\Clients\ClientAnalysisService;
use App\Services\Telegram\TelegramMessageFormatter;
use App\Services\Telegram\TelegramMessageSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeClientJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $clientId,
        public readonly int|string $chatId,
    ) {}

    public function handle(
        ClientAiProcessingLock $processingLock,
        ClientAnalysisService $clientAnalysisService,
        TelegramMessageSender $messageSender,
        TelegramMessageFormatter $messageFormatter,
        AiSensitiveDataMasker $masker,
    ): void {
        try {
            $run = $processingLock->run(
                $this->clientId,
                fn (): array => $clientAnalysisService->analyzeInitialClient($this->clientId, [
                    'queue_name' => $this->queue,
                    'job_class' => self::class,
                    'job_uuid' => $this->jobUuid(),
                ])
            );

            if (! $run['acquired']) {
                $messageSender->sendMessage($this->chatId, $messageFormatter->aiProcessingInProgressMessage());

                return;
            }

            $result = $run['result'];

            $messageSender->sendMessage(
                $this->chatId,
                $result['message'],
                $messageFormatter->startChatKeyboard($result['client']['id'])
            );

            UpdateClientSummaryJob::dispatch((int) $result['client']['id']);
        } catch (Throwable $exception) {
            Log::warning('Client analysis job failed.', [
                'client_id' => $this->clientId,
                'error' => $masker->maskText($exception->getMessage()),
            ]);

            $messageSender->sendMessage(
                $this->chatId,
                $messageFormatter->aiProcessingFailedMessage(),
                $messageFormatter->mainMenuKeyboard()
            );
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
