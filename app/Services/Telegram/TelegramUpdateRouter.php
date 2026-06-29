<?php

namespace App\Services\Telegram;

use App\Enums\ClientStatus;
use App\Jobs\AnalyzeClientJob;
use App\Jobs\GenerateReplySuggestionsJob;
use App\Jobs\RegenerateReplySuggestionsJob;
use App\Jobs\ReviewUserFeedbackJob;
use App\Jobs\UpdateClientSummaryJob;
use App\Services\Clients\ClientSessionManager;
use App\Services\Clients\CustomReplyService;
use App\Services\Clients\FeedbackReviewService;
use App\Services\Clients\MemorySummaryService;
use App\Services\Clients\ReplyRegenerationService;
use App\Services\Clients\SentReplySelectionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class TelegramUpdateRouter
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TelegramStateManager $stateManager,
        private readonly TelegramMessageSender $messageSender,
        private readonly TelegramMessageFormatter $messageFormatter,
        private readonly ClientSessionManager $clientSessionManager,
        private readonly SentReplySelectionService $sentReplySelectionService,
        private readonly FeedbackReviewService $feedbackReviewService,
        private readonly CustomReplyService $customReplyService,
        private readonly MemorySummaryService $memorySummaryService,
        private readonly ReplyRegenerationService $replyRegenerationService,
        private readonly TelegramCallbackParser $callbackParser,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, string $secret): JsonResponse
    {
        if (! $this->hasValidSecret($secret)) {
            return response()->json(['ok' => false], 403);
        }

        $updateId = data_get($payload, 'update_id');

        if ($updateId === null) {
            return response()->json(['ok' => false, 'message' => 'Missing update_id.'], 422);
        }

        if (! $this->recordUpdate($updateId, $payload)) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $callbackQuery = data_get($payload, 'callback_query');

        if (is_array($callbackQuery)) {
            $this->handleCallbackQuery($callbackQuery, $updateId);
            $this->markProcessed($updateId);

            return response()->json(['ok' => true]);
        }

        $message = data_get($payload, 'message');

        if (! is_array($message)) {
            $this->markProcessed($updateId);

            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $from = data_get($message, 'from');
        $chatId = data_get($message, 'chat.id');

        if (! is_array($from) || $chatId === null) {
            $this->markProcessed($updateId);

            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $telegramUserId = data_get($from, 'id');
        $user = $this->userResolver->resolve($from, $chatId);

        if ($telegramUserId !== null) {
            $this->attachUserToUpdate($updateId, $telegramUserId, $chatId);
        }

        if ($user === null) {
            $this->messageSender->sendMessage($chatId, $this->messageFormatter->accessDeniedMessage());
            $this->markProcessed($updateId);

            return response()->json(['ok' => true, 'unauthorized' => true]);
        }

        $this->routeMessage($user, $chatId, (string) data_get($message, 'text', ''));
        $this->markProcessed($updateId);

        return response()->json(['ok' => true]);
    }

    private function hasValidSecret(string $secret): bool
    {
        $configuredSecret = (string) config('telegram.webhook_secret');

        return $configuredSecret !== '' && hash_equals($configuredSecret, $secret);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordUpdate(int|string $updateId, array $payload): bool
    {
        try {
            DB::table('telegram_updates')->insert([
                'telegram_update_id' => $updateId,
                'payload' => $this->encodePayload($payload),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        } catch (QueryException $exception) {
            if ($this->updateExists($updateId)) {
                return false;
            }

            throw $exception;
        }
    }

    private function updateExists(int|string $updateId): bool
    {
        return DB::table('telegram_updates')
            ->where('telegram_update_id', $updateId)
            ->exists();
    }

    private function attachUserToUpdate(int|string $updateId, int|string $telegramUserId, int|string $chatId): void
    {
        DB::table('telegram_updates')
            ->where('telegram_update_id', $updateId)
            ->update([
                'telegram_user_id' => $telegramUserId,
                'chat_id' => $chatId,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $user
     */
    private function routeMessage(array $user, int|string $chatId, string $text): void
    {
        $telegramUserId = $user['telegram_user_id'];
        $currentState = $this->stateManager->getState($telegramUserId);
        $activeClientId = $currentState['active_client_id'] ?? null;
        $commandText = trim($text);

        if ($commandText === '/start') {
            $this->stateManager->clearState($telegramUserId);
            $this->sendMainMenu($chatId, $this->messageFormatter->startMessage());

            return;
        }

        if ($this->isNewClientCommand($commandText)) {
            $this->stateManager->setState(
                $telegramUserId,
                ClientSessionManager::STATE_WAITING_FOR_NEW_CLIENT_JOB,
                [],
                $activeClientId
            );
            $this->messageSender->sendMessage($chatId, $this->messageFormatter->requestNewClientJobMessage());

            return;
        }

        if ($this->isMyClientsCommand($commandText)) {
            $this->sendClientsList($telegramUserId, $chatId, $activeClientId);

            return;
        }

        if ($commandText === TelegramMessageFormatter::BUTTON_RESUME_CLIENT) {
            $this->resumeActiveClient($telegramUserId, $chatId, $activeClientId);

            return;
        }

        if ($commandText === TelegramMessageFormatter::BUTTON_PAUSE_CLIENT) {
            $this->pauseActiveClient($telegramUserId, $chatId, $activeClientId);

            return;
        }

        if ($commandText === TelegramMessageFormatter::BUTTON_PAUSE_CLIENT_FINISH) {
            $this->pauseActiveClient($telegramUserId, $chatId, $activeClientId);

            return;
        }

        if ($commandText === TelegramMessageFormatter::BUTTON_CLOSE_CLIENT) {
            $this->closeActiveClient($telegramUserId, $chatId, $activeClientId);

            return;
        }

        if ($commandText === TelegramMessageFormatter::BUTTON_VIEW_SUMMARY) {
            $this->sendClientSummary($telegramUserId, $chatId, $activeClientId);

            return;
        }

        if ($commandText === TelegramMessageFormatter::BUTTON_START_CHAT) {
            $this->startChat($telegramUserId, $chatId, $activeClientId);

            return;
        }

        $sentOptionNumber = $this->sentOptionNumber($commandText);

        if ($sentOptionNumber !== null) {
            $this->selectSentOption($telegramUserId, $chatId, $activeClientId, $sentOptionNumber);

            return;
        }

        if ($commandText === TelegramMessageFormatter::BUTTON_DISLIKE_REPLIES) {
            $this->startFeedbackMode($telegramUserId, $chatId, $activeClientId);

            return;
        }

        if ($commandText === TelegramMessageFormatter::BUTTON_OWN_REPLY) {
            $this->startCustomReplyMode($telegramUserId, $chatId, $activeClientId);

            return;
        }

        if ($commandText === TelegramMessageFormatter::BUTTON_REGENERATE) {
            $this->regenerateLatestReplySuggestion($telegramUserId, $chatId, $activeClientId);

            return;
        }

        if ($this->isFutureSprintButton($commandText)) {
            $this->messageSender->sendMessage(
                $chatId,
                $this->messageFormatter->futureSprintMessage(),
                $this->messageFormatter->replySuggestionsKeyboard()
            );

            return;
        }

        if (($currentState['state'] ?? null) === ClientSessionManager::STATE_WAITING_FOR_CUSTOM_REPLY) {
            $this->handleCustomReply($telegramUserId, $chatId, $activeClientId, $currentState, $text);

            return;
        }

        if (($currentState['state'] ?? null) === ClientSessionManager::STATE_WAITING_FOR_FEEDBACK_REASON) {
            $this->handleFeedbackReason($telegramUserId, $chatId, $activeClientId, $currentState, $commandText);

            return;
        }

        if (($currentState['state'] ?? null) === ClientSessionManager::STATE_WAITING_FOR_NEW_CLIENT_JOB) {
            $this->createClientFromMessage($telegramUserId, $chatId, $commandText);

            return;
        }

        if (($currentState['state'] ?? null) === ClientSessionManager::STATE_CHATTING_WITH_CLIENT) {
            $this->handleClientChatMessage($telegramUserId, $chatId, $activeClientId, $commandText);

            return;
        }

        $this->sendMainMenu($chatId, $this->messageFormatter->unknownCommandMessage());
    }

    private function sendMainMenu(int|string $chatId, string $message): void
    {
        $this->messageSender->sendMessage(
            $chatId,
            $message,
            $this->messageFormatter->mainMenuKeyboard()
        );
    }

    /**
     * @param  array<string, mixed>  $callbackQuery
     */
    private function handleCallbackQuery(array $callbackQuery, int|string $updateId): void
    {
        $callbackQueryId = data_get($callbackQuery, 'id');

        if ($callbackQueryId !== null) {
            $this->messageSender->answerCallbackQuery((string) $callbackQueryId);
        }

        $from = data_get($callbackQuery, 'from');
        $chatId = data_get($callbackQuery, 'message.chat.id');

        if (! is_array($from) || $chatId === null) {
            return;
        }

        $telegramUserId = data_get($from, 'id');
        $user = $this->userResolver->resolve($from, $chatId);

        if ($telegramUserId !== null) {
            $this->attachUserToUpdate($updateId, $telegramUserId, $chatId);
        }

        if ($user === null) {
            $this->messageSender->sendMessage($chatId, $this->messageFormatter->accessDeniedMessage());

            return;
        }

        $callbackData = data_get($callbackQuery, 'data');
        $callback = $this->callbackParser->parse(is_string($callbackData) ? $callbackData : null);

        if ($callback === null) {
            $this->messageSender->sendMessage(
                $chatId,
                $this->messageFormatter->staleSuggestionMessage(),
                $this->messageFormatter->mainMenuKeyboard()
            );

            return;
        }

        $this->routeCallback($user, $chatId, $callback);
    }

    /**
     * @param  array<string, mixed>  $user
     * @param  array<string, int|string>  $callback
     */
    private function routeCallback(array $user, int|string $chatId, array $callback): void
    {
        $telegramUserId = $user['telegram_user_id'];

        match ($callback['action']) {
            'client_resume' => $this->resumeActiveClient($telegramUserId, $chatId, $callback['client_id']),
            'client_pause' => $this->pauseActiveClient($telegramUserId, $chatId, $callback['client_id']),
            'client_close' => $this->closeActiveClient($telegramUserId, $chatId, $callback['client_id']),
            'client_summary' => $this->sendClientSummary($telegramUserId, $chatId, $callback['client_id']),
            'start_chat' => $this->startChat($telegramUserId, $chatId, $callback['client_id']),
            'select_option' => $this->selectSentOptionBySuggestion($telegramUserId, $chatId, $callback['suggestion_id'], $callback['option_number']),
            'feedback' => $this->startFeedbackMode($telegramUserId, $chatId, null, $callback['suggestion_id']),
            'custom_reply' => $this->startCustomReplyMode($telegramUserId, $chatId, null, $callback['suggestion_id']),
            'regenerate' => $this->regenerateLatestReplySuggestion($telegramUserId, $chatId, null, $callback['suggestion_id']),
            default => $this->messageSender->sendMessage(
                $chatId,
                $this->messageFormatter->staleSuggestionMessage(),
                $this->messageFormatter->mainMenuKeyboard()
            ),
        };
    }

    private function startFeedbackMode(
        int|string $telegramUserId,
        int|string $chatId,
        int|string|null $activeClientId,
        int|string|null $suggestionId = null
    ): void
    {
        $suggestion = $suggestionId === null
            ? $this->feedbackReviewService->latestGeneratedSuggestion($telegramUserId, $activeClientId)
            : $this->feedbackReviewService->generatedSuggestionForUser($telegramUserId, $suggestionId);

        if ($suggestion === null) {
            $this->messageSender->sendMessage(
                $chatId,
                $this->messageFormatter->staleSuggestionMessage(),
                $this->messageFormatter->replySuggestionsKeyboard()
            );

            return;
        }

        $this->stateManager->setState(
            $telegramUserId,
            ClientSessionManager::STATE_WAITING_FOR_FEEDBACK_REASON,
            ['bot_suggestion_id' => $suggestion->id],
            $suggestion->client_id
        );

        $this->messageSender->sendMessage($chatId, $this->messageFormatter->feedbackReasonPromptMessage());
    }

    private function startCustomReplyMode(
        int|string $telegramUserId,
        int|string $chatId,
        int|string|null $activeClientId,
        int|string|null $suggestionId = null
    ): void
    {
        $context = $this->customReplyService->contextForCustomReply($telegramUserId, $activeClientId, $suggestionId);

        if ($context === null) {
            if ($suggestionId !== null) {
                $this->messageSender->sendMessage(
                    $chatId,
                    $this->messageFormatter->staleSuggestionMessage(),
                    $this->messageFormatter->replySuggestionsKeyboard()
                );
            } else {
                $this->sendNoActiveClient($chatId);
            }

            return;
        }

        $payload = [
            'client_id' => $context['client_id'],
        ];

        if ($context['suggestion_id'] !== null) {
            $payload['suggestion_id'] = $context['suggestion_id'];
        }

        $this->stateManager->setState(
            $telegramUserId,
            ClientSessionManager::STATE_WAITING_FOR_CUSTOM_REPLY,
            $payload,
            $context['client_id']
        );

        $this->messageSender->sendMessage($chatId, $this->messageFormatter->customReplyPromptMessage());
    }

    private function regenerateLatestReplySuggestion(
        int|string $telegramUserId,
        int|string $chatId,
        int|string|null $activeClientId,
        int|string|null $suggestionId = null
    ): void
    {
        $result = $suggestionId === null
            ? $this->replyRegenerationService->latestForUser($telegramUserId, $activeClientId)
            : $this->replyRegenerationService->forSuggestion($telegramUserId, $suggestionId);

        if ($result['status'] === 'missing_active_client') {
            $this->sendNoActiveClient($chatId);

            return;
        }

        $activeClientId = $result['suggestion']?->client_id ?? $activeClientId;

        $this->stateManager->setState(
            $telegramUserId,
            ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
            [],
            $activeClientId
        );

        if ($result['status'] === 'already_selected') {
            $this->messageSender->sendMessage(
                $chatId,
                $this->messageFormatter->alreadySentCannotRegenerateMessage(),
                $this->messageFormatter->mainMenuKeyboard()
            );

            return;
        }

        if ($result['status'] !== 'ready' || $result['suggestion'] === null) {
            $this->messageSender->sendMessage(
                $chatId,
                $this->messageFormatter->staleSuggestionMessage(),
                $this->messageFormatter->replySuggestionsKeyboard()
            );

            return;
        }

        $this->messageSender->sendMessage(
            $chatId,
            $this->messageFormatter->regeneratingReplySuggestionsMessage()
        );

        RegenerateReplySuggestionsJob::dispatch((int) $result['suggestion']->id, $chatId);
    }

    /**
     * @param  array{state: string|null, payload: array<string, mixed>, active_client_id: int|null}|null  $currentState
     */
    private function handleCustomReply(
        int|string $telegramUserId,
        int|string $chatId,
        int|string|null $activeClientId,
        ?array $currentState,
        string $text
    ): void {
        if (trim($text) === '') {
            $this->messageSender->sendMessage($chatId, $this->messageFormatter->emptyCustomReplyMessage());

            return;
        }

        $result = $this->customReplyService->storeCustomReply(
            $telegramUserId,
            data_get($currentState, 'payload.client_id', $activeClientId),
            data_get($currentState, 'payload.suggestion_id'),
            $text
        );

        if ($result === null) {
            $this->stateManager->setState(
                $telegramUserId,
                ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
                [],
                $activeClientId
            );

            $this->sendNoActiveClient($chatId);

            return;
        }

        $this->stateManager->setState(
            $telegramUserId,
            ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
            [],
            $result['client']['id']
        );

        $this->messageSender->sendMessage(
            $chatId,
            $this->messageFormatter->customReplySavedMessage($result['analysis']),
            $this->messageFormatter->mainMenuKeyboard()
        );

        UpdateClientSummaryJob::dispatch((int) $result['client']['id']);
    }

    /**
     * @param  array{state: string|null, payload: array<string, mixed>, active_client_id: int|null}|null  $currentState
     */
    private function handleFeedbackReason(
        int|string $telegramUserId,
        int|string $chatId,
        int|string|null $activeClientId,
        ?array $currentState,
        string $text
    ): void {
        if ($text === '') {
            $this->messageSender->sendMessage($chatId, $this->messageFormatter->emptyFeedbackReasonMessage());

            return;
        }

        $feedback = $this->feedbackReviewService->storePendingFeedback(
            $telegramUserId,
            $activeClientId,
            data_get($currentState, 'payload.bot_suggestion_id'),
            $text
        );

        if ($feedback === null) {
            $this->stateManager->setState(
                $telegramUserId,
                ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
                [],
                $activeClientId
            );

            $this->messageSender->sendMessage(
                $chatId,
                $this->messageFormatter->staleSuggestionMessage(),
                $this->messageFormatter->replySuggestionsKeyboard()
            );

            return;
        }

        ReviewUserFeedbackJob::dispatch((int) $feedback->id, $chatId);
    }

    private function createClientFromMessage(int|string $telegramUserId, int|string $chatId, string $text): void
    {
        if ($text === '') {
            $this->messageSender->sendMessage($chatId, $this->messageFormatter->emptyClientMessage());

            return;
        }

        $client = $this->clientSessionManager->createClientFromInitialMessage($telegramUserId, $text);

        $this->stateManager->setState($telegramUserId, null, [], $client['id']);
        $this->messageSender->sendMessage(
            $chatId,
            $this->messageFormatter->analyzingClientMessage()
        );

        AnalyzeClientJob::dispatch((int) $client['id'], $chatId);
    }

    private function handleClientChatMessage(int|string $telegramUserId, int|string $chatId, int|string|null $activeClientId, string $text): void
    {
        if ($text === '') {
            $this->messageSender->sendMessage($chatId, $this->messageFormatter->emptyClientMessage());

            return;
        }

        $result = $this->clientSessionManager->storeClientMessage($telegramUserId, $activeClientId, $text);

        if ($result === null) {
            $this->sendNoActiveClient($chatId);

            return;
        }

        $this->messageSender->sendMessage($chatId, $this->messageFormatter->analyzingClientReplyMessage());

        GenerateReplySuggestionsJob::dispatch(
            (int) $result['client']['id'],
            (int) $result['message']['id'],
            $chatId
        );
    }

    private function sendClientsList(int|string $telegramUserId, int|string $chatId, int|string|null $activeClientId): void
    {
        $clients = $this->clientSessionManager->listClients($telegramUserId);
        $activeClientId = $this->ensureActiveClient($telegramUserId, $clients, $activeClientId);

        $this->messageSender->sendMessage(
            $chatId,
            $this->messageFormatter->clientsListMessage($clients, $activeClientId),
            $clients->isEmpty()
                ? $this->messageFormatter->mainMenuKeyboard()
                : $this->messageFormatter->clientActionsKeyboard($activeClientId)
        );
    }

    /**
     * @param  Collection<int, object>  $clients
     */
    private function ensureActiveClient(int|string $telegramUserId, Collection $clients, int|string|null $activeClientId): int|string|null
    {
        if ($activeClientId !== null && $activeClientId !== '' && $clients->contains('id', $activeClientId)) {
            return $activeClientId;
        }

        $client = $clients->first(fn (object $client): bool => $client->status !== ClientStatus::Closed->value)
            ?? $clients->first();

        if ($client === null) {
            return null;
        }

        $this->stateManager->setActiveClient($telegramUserId, $client->id);

        return $client->id;
    }

    private function resumeActiveClient(int|string $telegramUserId, int|string $chatId, int|string|null $activeClientId): void
    {
        $client = $this->clientSessionManager->resumeClient($telegramUserId, $activeClientId);

        if ($client === null) {
            $this->sendNoActiveClient($chatId);

            return;
        }

        $this->stateManager->setActiveClient($telegramUserId, $client['id']);
        $this->messageSender->sendMessage(
            $chatId,
            $this->messageFormatter->clientResumedMessage($client),
            $this->messageFormatter->clientActionsKeyboard($client['id'])
        );
    }

    private function pauseActiveClient(int|string $telegramUserId, int|string $chatId, int|string|null $activeClientId): void
    {
        $client = $this->clientSessionManager->pauseClient($telegramUserId, $activeClientId);

        if ($client === null) {
            $this->sendNoActiveClient($chatId);

            return;
        }

        $this->stateManager->setActiveClient($telegramUserId, $client['id']);
        $this->messageSender->sendMessage(
            $chatId,
            $this->messageFormatter->clientPausedMessage($client),
            $this->messageFormatter->clientActionsKeyboard($client['id'])
        );
    }

    private function closeActiveClient(int|string $telegramUserId, int|string $chatId, int|string|null $activeClientId): void
    {
        $client = $this->clientSessionManager->closeClient($telegramUserId, $activeClientId);

        if ($client === null) {
            $this->sendNoActiveClient($chatId);

            return;
        }

        $this->stateManager->setActiveClient($telegramUserId, $client['id']);
        $this->messageSender->sendMessage(
            $chatId,
            $this->messageFormatter->clientClosedMessage($client),
            $this->messageFormatter->clientActionsKeyboard($client['id'])
        );
    }

    private function sendClientSummary(int|string $telegramUserId, int|string $chatId, int|string|null $activeClientId): void
    {
        $result = $this->memorySummaryService->summaryForActiveClient($telegramUserId, $activeClientId);

        if ($result === null) {
            $this->sendNoActiveClient($chatId);

            return;
        }

        $client = $result['client'];
        $summary = $result['summary'];

        $this->messageSender->sendMessage(
            $chatId,
            $summary === null
                ? $this->messageFormatter->summaryUnavailableMessage($client)
                : $this->messageFormatter->clientSummaryMessage($client, $summary),
            $this->messageFormatter->clientActionsKeyboard($client['id'])
        );
    }

    private function startChat(int|string $telegramUserId, int|string $chatId, int|string|null $activeClientId): void
    {
        $client = $this->clientSessionManager->startChatting($telegramUserId, $activeClientId);

        if ($client === null) {
            $this->sendNoActiveClient($chatId);

            return;
        }

        $this->stateManager->setState(
            $telegramUserId,
            ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
            [],
            $client['id']
        );

        $this->messageSender->sendMessage(
            $chatId,
            $this->messageFormatter->startChatPromptMessage(),
            $this->messageFormatter->mainMenuKeyboard()
        );
    }

    private function sendNoActiveClient(int|string $chatId): void
    {
        $this->messageSender->sendMessage(
            $chatId,
            $this->messageFormatter->noActiveClientMessage(),
            $this->messageFormatter->mainMenuKeyboard()
        );
    }

    private function selectSentOption(int|string $telegramUserId, int|string $chatId, int|string|null $activeClientId, int $optionNumber): void
    {
        $result = $this->sentReplySelectionService->selectLatestOption($telegramUserId, $activeClientId, $optionNumber);

        $message = match ($result['status']) {
            'selected' => $this->messageFormatter->sentReplySavedMessage(),
            'already_selected' => $this->messageFormatter->alreadySelectedReplyMessage(),
            default => $this->messageFormatter->staleSuggestionMessage(),
        };

        $this->stateManager->setState(
            $telegramUserId,
            ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
            [],
            $activeClientId
        );

        $this->messageSender->sendMessage(
            $chatId,
            $message,
            $this->messageFormatter->mainMenuKeyboard()
        );

        if ($result['status'] === 'selected' && $result['suggestion'] !== null) {
            UpdateClientSummaryJob::dispatch((int) $result['suggestion']->client_id);
        }
    }

    private function selectSentOptionBySuggestion(int|string $telegramUserId, int|string $chatId, int|string $suggestionId, int $optionNumber): void
    {
        $result = $this->sentReplySelectionService->selectOption($telegramUserId, $suggestionId, $optionNumber);
        $activeClientId = $result['suggestion']?->client_id;

        $message = match ($result['status']) {
            'selected' => $this->messageFormatter->sentReplySavedMessage(),
            'already_selected' => $this->messageFormatter->alreadySelectedReplyMessage(),
            default => $this->messageFormatter->staleSuggestionMessage(),
        };

        if ($activeClientId !== null) {
            $this->stateManager->setState(
                $telegramUserId,
                ClientSessionManager::STATE_CHATTING_WITH_CLIENT,
                [],
                $activeClientId
            );
        }

        $this->messageSender->sendMessage(
            $chatId,
            $message,
            $this->messageFormatter->mainMenuKeyboard()
        );

        if ($result['status'] === 'selected' && $result['suggestion'] !== null) {
            UpdateClientSummaryJob::dispatch((int) $result['suggestion']->client_id);
        }
    }

    private function sentOptionNumber(string $text): ?int
    {
        return match ($text) {
            TelegramMessageFormatter::BUTTON_SENT_OPTION_1 => 1,
            TelegramMessageFormatter::BUTTON_SENT_OPTION_2 => 2,
            TelegramMessageFormatter::BUTTON_SENT_OPTION_3 => 3,
            default => null,
        };
    }

    private function isNewClientCommand(string $text): bool
    {
        return $this->normalizedMenuCommandKey($text) === 'new client';
    }

    private function isMyClientsCommand(string $text): bool
    {
        return $this->normalizedMenuCommandKey($text) === 'my clients';
    }

    private function normalizedMenuCommandKey(string $text): string
    {
        $normalized = trim(str_replace(["\u{FE0F}", "\u{200D}", "\u{00A0}"], ' ', $text));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        // Strip leading symbols/emojis so Telegram font/client variations do not break reply-keyboard commands.
        $normalized = preg_replace('/^[^\p{L}\p{N}]+/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);

        return mb_strtolower($normalized);
    }

    private function isFutureSprintButton(string $text): bool
    {
        return false;
    }

    private function markProcessed(int|string $updateId): void
    {
        DB::table('telegram_updates')
            ->where('telegram_update_id', $updateId)
            ->update([
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodePayload(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            return '{}';
        }
    }
}
