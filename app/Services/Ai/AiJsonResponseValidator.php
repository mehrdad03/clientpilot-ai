<?php

namespace App\Services\Ai;

class AiJsonResponseValidator
{
    /**
     * @param  array<int, string>  $requiredKeys
     * @return array{valid: bool, data: array<string, mixed>|null, errors: array<int, string>}
     */
    public function validate(string $json, array $requiredKeys = []): array
    {
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return [
                'valid' => false,
                'data' => null,
                'errors' => ['Response is not valid JSON.'],
            ];
        }

        $errors = [];

        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $decoded)) {
                $errors[] = "Missing required key [{$key}].";
            }
        }

        return [
            'valid' => $errors === [],
            'data' => $decoded,
            'errors' => $errors,
        ];
    }
}
