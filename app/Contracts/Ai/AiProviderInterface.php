<?php

namespace App\Contracts\Ai;

interface AiProviderInterface
{
    /**
     * @param  array<int, array<string, mixed>>|string  $input
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function createResponse(array|string $input, array $options = []): array;
}
