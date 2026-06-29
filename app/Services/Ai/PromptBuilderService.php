<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class PromptBuilderService
{
    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function build(string $promptKey, ?string $version = null, array $variables = []): string
    {
        $content = File::get($this->pathFor($promptKey, $version));

        foreach ($variables as $key => $value) {
            $content = str_replace('{{ '.$key.' }}', (string) $value, $content);
            $content = str_replace('{{'.$key.'}}', (string) $value, $content);
        }

        return $content;
    }

    public function pathFor(string $promptKey, ?string $version = null): string
    {
        $version ??= (string) config('ai.prompts.default_version', 'v1');
        $file = config("ai.prompts.files.{$promptKey}.{$version}");

        if (! is_string($file) || $file === '') {
            throw new InvalidArgumentException("Prompt [{$promptKey}:{$version}] is not configured.");
        }

        $path = rtrim((string) config('ai.prompts.base_path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file;

        if (! File::exists($path)) {
            throw new InvalidArgumentException("Prompt file does not exist for [{$promptKey}:{$version}].");
        }

        return $path;
    }
}
