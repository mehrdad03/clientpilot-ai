<?php

namespace App\Services\Clients;

use App\Services\Ai\AiSensitiveDataMasker;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClientAiProcessingLock
{
    private const LOCK_TTL_SECONDS = 180;

    public function __construct(
        private readonly AiSensitiveDataMasker $masker,
    ) {}

    /**
     * @return array{acquired: bool, result: mixed}
     */
    public function run(int|string $clientId, Closure $callback): array
    {
        $lock = Cache::lock($this->key($clientId), self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            return [
                'acquired' => false,
                'result' => null,
            ];
        }

        try {
            return [
                'acquired' => true,
                'result' => $callback(),
            ];
        } finally {
            try {
                $lock->release();
            } catch (Throwable $exception) {
                Log::warning('Client AI processing lock release failed.', [
                    'client_id' => $clientId,
                    'error' => $this->masker->maskText($exception->getMessage()),
                ]);
            }
        }
    }

    private function key(int|string $clientId): string
    {
        return 'client-ai-processing:'.$clientId;
    }
}
