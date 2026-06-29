<?php

namespace App\Services\Ai;

class AiSensitiveDataMasker
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function maskArray(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->maskArray($value);

                continue;
            }

            if ($this->isSensitiveKey((string) $key)) {
                $payload[$key] = '[masked]';

                continue;
            }

            if (is_string($value)) {
                $payload[$key] = $this->maskText($value);
            }
        }

        return $payload;
    }

    public function maskText(string $text): string
    {
        $text = preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', '[masked-email]', $text) ?? $text;
        $text = preg_replace('/\b(?:\+?\d[\s().-]*){8,}\b/', '[masked-phone]', $text) ?? $text;
        $text = preg_replace('/\b(?:sk|sess|rk)-[A-Za-z0-9_\-]{12,}\b/', '[masked-token]', $text) ?? $text;
        $text = preg_replace('/\b\d{8,}:[A-Za-z0-9_\-]{20,}\b/', '[masked-token]', $text) ?? $text;
        $text = preg_replace('/\bBearer\s+[A-Za-z0-9._\-_]{12,}\b/i', 'Bearer [masked-token]', $text) ?? $text;
        $text = preg_replace('/\b(password|secret|token|api[_\s-]?key)\s*[:=]\s*\S+/i', '$1=[masked]', $text) ?? $text;

        return $text;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        return str_contains($normalized, 'key')
            || str_contains($normalized, 'token')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'password')
            || str_contains($normalized, 'authorization');
    }
}
