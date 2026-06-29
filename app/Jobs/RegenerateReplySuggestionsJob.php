<?php

namespace App\Jobs;

use App\Services\Ai\AiSensitiveDataMasker;
use App\Services\Clients\ClientAiProcessingLock;
use App\Services\Clients\ReplyRegenerationService;
use App\Services\Telegram\TelegramMessageFormatter;
use App\Services\Telegram\TelegramMessageSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RegenerateReplySuggestionsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $botSuggestionId,
        public readonly int|string $chatId,
    ) {}

    public function handle(
        ClientAiProcessingLock $processingLock,
        ReplyRegenerationService $regenerationService,
        TelegramMessageSender $messageSender,
        TelegramMessageFormatter $messageFormatter,
        AiSensitiveDataMasker $masker,
    ): void {
        $context = $regenerationService->jobContext($this->botSuggestionId);

        if ($context === null) {
            $messageSender->sendMessage(
                $this->chatId,
                $messageFormatter->staleSuggestionMessage(),
                $messageFormatter->replySuggestionsKeyboard()
            );

            return;
        }

        try {
            $run = $processingLock->run(
                $context['client_id'],
                fn (): array => $regenerationService->regenerate($this->botSuggestionId, [
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

            match ($result['status']) {
                'regenerated' => $messageSender->sendMessage(
                    $this->chatId,
                    $result['message'],
                    $messageFormatter->replySuggestionsKeyboard($result['suggestion']->id, $context['client_id']),
                    TelegramMessageFormatter::PARSE_MODE_HTML
                ),
                'already_selected' => $messageSender->sendMessage(
                    $this->chatId,
                    $messageFormatter->alreadySentCannotRegenerateMessage(),
                    $messageFormatter->mainMenuKeyboard()
                ),
                default => $messageSender->sendMessage(
                    $this->chatId,
                    $messageFormatter->staleSuggestionMessage(),
                    $messageFormatter->replySuggestionsKeyboard()
                ),
            };
        } catch (Throwable $exception) {
            Log::warning('Reply regeneration job failed.', [
                'bot_suggestion_id' => $this->botSuggestionId,
                'client_id' => $context['client_id'],
                'error' => $masker->maskText($exception->getMessage()),
            ]);

            $messageSender->sendMessage(
                $this->chatId,
                $messageFormatter->aiProcessingFailedMessage(),
                $messageFormatter->replySuggestionsKeyboard()
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
