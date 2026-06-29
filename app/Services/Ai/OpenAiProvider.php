<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiProviderInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiProvider implements AiProviderInterface
{
    /**
     * @param  array<int, array<string, mixed>>|string  $input
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function createResponse(array|string $input, array $options = []): array
    {
        $apiKey = (string) config('ai.providers.openai.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $baseUrl = rtrim((string) config('ai.providers.openai.base_url'), '/');
        $model = $options['model'] ?? config('ai.providers.openai.model');
        $timeout = (int) ($options['timeout'] ?? config('ai.providers.openai.timeout'));

        $payload = array_merge([
            'model' => $model,
            'input' => $input,
            'store' => (bool) ($options['store'] ?? config('ai.providers.openai.store_responses')),
        ], Arr::except($options, ['model', 'timeout', 'store']));

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->post("{$baseUrl}/responses", $payload);

        $response->throw();

        return $response->json();
    }
}
