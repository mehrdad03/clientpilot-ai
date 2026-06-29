<?php

namespace App\Services\Telegram;

class TelegramCallbackParser
{
    /**
     * @return array<string, int|string>|null
     */
    public function parse(?string $callbackData): ?array
    {
        if ($callbackData === null || $callbackData === '' || strlen($callbackData) > 64) {
            return null;
        }

        $parts = explode(':', $callbackData);

        return match ($parts[0] ?? null) {
            'cl' => $this->parseClientCallback($parts),
            'chat' => $this->parseChatCallback($parts),
            'sg' => $this->parseSuggestionCallback($parts),
            default => null,
        };
    }

    /**
     * @param  array<int, string>  $parts
     * @return array<string, int|string>|null
     */
    private function parseClientCallback(array $parts): ?array
    {
        if (count($parts) !== 3 || ! $this->isPositiveInteger($parts[2])) {
            return null;
        }

        $action = match ($parts[1]) {
            'rs' => 'client_resume',
            'pa' => 'client_pause',
            'cl' => 'client_close',
            'sum' => 'client_summary',
            default => null,
        };

        if ($action === null) {
            return null;
        }

        return [
            'action' => $action,
            'client_id' => (int) $parts[2],
        ];
    }

    /**
     * @param  array<int, string>  $parts
     * @return array<string, int|string>|null
     */
    private function parseChatCallback(array $parts): ?array
    {
        if (count($parts) !== 3 || $parts[1] !== 'start' || ! $this->isPositiveInteger($parts[2])) {
            return null;
        }

        return [
            'action' => 'start_chat',
            'client_id' => (int) $parts[2],
        ];
    }

    /**
     * @param  array<int, string>  $parts
     * @return array<string, int|string>|null
     */
    private function parseSuggestionCallback(array $parts): ?array
    {
        if (count($parts) < 3 || ! $this->isPositiveInteger($parts[2])) {
            return null;
        }

        if ($parts[1] === 'sel') {
            if (count($parts) !== 4 || ! in_array($parts[3], ['1', '2', '3'], true)) {
                return null;
            }

            return [
                'action' => 'select_option',
                'suggestion_id' => (int) $parts[2],
                'option_number' => (int) $parts[3],
            ];
        }

        if (count($parts) !== 3) {
            return null;
        }

        $action = match ($parts[1]) {
            'rg' => 'regenerate',
            'fb' => 'feedback',
            'custom' => 'custom_reply',
            default => null,
        };

        if ($action === null) {
            return null;
        }

        return [
            'action' => $action,
            'suggestion_id' => (int) $parts[2],
        ];
    }

    private function isPositiveInteger(string $value): bool
    {
        return preg_match('/^[1-9][0-9]*$/', $value) === 1;
    }
}
