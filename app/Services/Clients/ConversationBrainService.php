<?php

namespace App\Services\Clients;

use App\Enums\ConversationMessageType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConversationBrainService
{
    private const RECENT_MESSAGE_LIMIT = 12;

    /**
     * @return array{client: array<string, mixed>, latest_client_message: array<string, mixed>, conversation: Collection<int, object>, summary: object|null}
     */
    public function contextForReplySuggestions(int|string $clientId, int|string $conversationMessageId): array
    {
        $client = DB::table('clients')->where('id', $clientId)->first();

        if ($client === null) {
            throw new RuntimeException("Client [{$clientId}] was not found.");
        }

        $message = DB::table('conversation_messages')
            ->where('id', $conversationMessageId)
            ->where('client_id', $clientId)
            ->where('message_type', ConversationMessageType::ClientMessage->value)
            ->first();

        if ($message === null) {
            throw new RuntimeException("Client message [{$conversationMessageId}] was not found.");
        }

        return [
            'client' => (array) $client,
            'latest_client_message' => (array) $message,
            'conversation' => $this->recentMessagesForClient($clientId),
            'summary' => $this->summaryForClient($clientId),
        ];
    }

    /**
     * @return array{suggestion: array<string, mixed>, client: array<string, mixed>, latest_client_message: array<string, mixed>, conversation: Collection<int, object>, summary: object|null}
     */
    public function contextForSuggestion(int|string $botSuggestionId): array
    {
        $suggestion = DB::table('bot_suggestions')->where('id', $botSuggestionId)->first();

        if ($suggestion === null) {
            throw new RuntimeException("Bot suggestion [{$botSuggestionId}] was not found.");
        }

        $client = DB::table('clients')->where('id', $suggestion->client_id)->first();

        if ($client === null) {
            throw new RuntimeException("Client [{$suggestion->client_id}] was not found.");
        }

        $message = DB::table('conversation_messages')
            ->where('id', $suggestion->conversation_message_id)
            ->where('client_id', $suggestion->client_id)
            ->first();

        if ($message === null) {
            throw new RuntimeException("Conversation message [{$suggestion->conversation_message_id}] was not found.");
        }

        return [
            'suggestion' => (array) $suggestion,
            'client' => (array) $client,
            'latest_client_message' => (array) $message,
            'conversation' => $this->recentMessagesForClient($suggestion->client_id),
            'summary' => $this->summaryForClient($suggestion->client_id),
        ];
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

    private function summaryForClient(int|string $clientId): ?object
    {
        return DB::table('client_summaries')
            ->where('client_id', $clientId)
            ->first();
    }

    /**
     * @param  Collection<int, object>  $messages
     */
    public function formatConversation(Collection $messages, ?object $summary = null): string
    {
        $memorySummary = $summary === null
            ? 'No memory summary available yet.'
            : implode("\n", [
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
            ]);

        $recentMessages = $messages
            ->map(fn (object $message): string => "[{$message->sender}:{$message->message_type}] {$message->body}")
            ->implode("\n\n");

        return implode("\n\n", [
            "Memory summary:\n{$memorySummary}",
            "Recent messages:\n".($recentMessages === '' ? 'No recent messages yet.' : $recentMessages),
        ]);
    }
}
