<?php

namespace App\Jobs;

use App\Services\Ai\AiSensitiveDataMasker;
use App\Services\Clients\ClientAiProcessingLock;
use App\Services\Clients\ReplySuggestionService;
use App\Services\Telegram\TelegramMessageFormatter;
use App\Services\Telegram\TelegramMessageSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateReplySuggestionsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $clientId,
        public readonly int $conversationMessageId,
        public readonly int|string $chatId,
    ) {}

    public function handle(
        ClientAiProcessingLock $processingLock,
        ReplySuggestionService $replySuggestionService,
        TelegramMessageSender $messageSender,
        TelegramMessageFormatter $messageFormatter,
        AiSensitiveDataMasker $masker,
    ): void {
        try {
            $run = $processingLock->run(
                $this->clientId,
                fn (): array => $replySuggestionService->generateForClientMessage($this->clientId, $this->conversationMessageId, [
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
                $messageFormatter->replySuggestionsKeyboard($result['suggestion']->id, $this->clientId),
                TelegramMessageFormatter::PARSE_MODE_HTML
            );

            UpdateClientSummaryJob::dispatch($this->clientId);
        } catch (Throwable $exception) {
            Log::warning('Reply suggestions job failed.', [
                'client_id' => $this->clientId,
                'conversation_message_id' => $this->conversationMessageId,
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
