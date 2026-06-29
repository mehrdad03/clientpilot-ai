<?php

namespace App\Jobs;

use App\Services\Ai\AiSensitiveDataMasker;
use App\Services\Clients\ClientAiProcessingLock;
use App\Services\Clients\ClientSessionManager;
use App\Services\Clients\FeedbackReviewService;
use App\Services\Telegram\TelegramMessageFormatter;
use App\Services\Telegram\TelegramMessageSender;
use App\Services\Telegram\TelegramStateManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReviewUserFeedbackJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $userFeedbackId,
        public readonly int|string $chatId,
    ) {}

    public function handle(
        ClientAiProcessingLock $processingLock,
        FeedbackReviewService $feedbackReviewService,
        TelegramMessageSender $messageSender,
        TelegramMessageFormatter $messageFormatter,
        TelegramStateManager $stateManager,
        AiSensitiveDataMasker $masker,
    ): void {
        $feedbackContext = $this->feedbackContext();

        try {
            if ($feedbackContext === null) {
                throw new \RuntimeException("User feedback [{$this->userFeedbackId}] was not found.");
            }

            $run = $processingLock->run(
                $feedbackContext['client_id'],
                fn (): array => $feedbackReviewService->reviewFeedback($this->userFeedbackId, [
                    'queue_name' => $this->queue,
                    'job_class' => self::class,
                    'job_uuid' => $this->jobUuid(),
                ])
            );

            if (! $run['acquired']) {
                $stateManager->setState(
                    $feedbackContext['telegram_user_id'],
                    ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
                    [],
                    $feedbackContext['client_id']
                );
                $messageSender->sendMessage($this->chatId, $messageFormatter->aiProcessingInProgressMessage());

                return;
            }

            $result = $run['result'];

            $stateManager->setState(
                $result['feedback']->telegram_user_id,
                ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
                [],
                $result['feedback']->client_id
            );

            $messageSender->sendMessage(
                $this->chatId,
                $result['message'],
                $messageFormatter->replySuggestionsKeyboard(
                    $result['suggestion']?->id ?? $result['feedback']->bot_suggestion_id,
                    $result['feedback']->client_id
                ),
                $result['suggestion'] !== null ? TelegramMessageFormatter::PARSE_MODE_HTML : null
            );

            if ($result['suggestion'] !== null) {
                UpdateClientSummaryJob::dispatch((int) $result['feedback']->client_id);
            }
        } catch (Throwable $exception) {
            Log::warning('Feedback review job failed.', [
                'user_feedback_id' => $this->userFeedbackId,
                'client_id' => $feedbackContext['client_id'] ?? null,
                'error' => $masker->maskText($exception->getMessage()),
            ]);

            if ($feedbackContext !== null) {
                $stateManager->setState(
                    $feedbackContext['telegram_user_id'],
                    ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
                    [],
                    $feedbackContext['client_id']
                );
            }

            $messageSender->sendMessage(
                $this->chatId,
                $messageFormatter->aiProcessingFailedMessage(),
                $messageFormatter->mainMenuKeyboard()
            );
        }
    }

    /**
     * @return array{client_id: int, telegram_user_id: int}|null
     */
    private function feedbackContext(): ?array
    {
        $feedback = DB::table('user_feedbacks')
            ->where('id', $this->userFeedbackId)
            ->first(['client_id', 'telegram_user_id']);

        if ($feedback === null) {
            return null;
        }

        return [
            'client_id' => (int) $feedback->client_id,
            'telegram_user_id' => (int) $feedback->telegram_user_id,
        ];
    }

    private function jobUuid(): ?string
    {
        if ($this->job === null || ! method_exists($this->job, 'uuid')) {
            return null;
        }

        return $this->job->uuid();
    }
}
