<?php

namespace Tests\Unit;

use App\Services\Ai\PromptBuilderService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PromptBuilderServiceTest extends TestCase
{
    public function test_it_builds_a_versioned_prompt(): void
    {
        $service = app(PromptBuilderService::class);

        $prompt = $service->build('sales_copilot_analysis', 'v1');

        $this->assertStringContainsString('sales_copilot_analysis_v1', $prompt);
    }

    public function test_it_replaces_variables_in_prompt_templates(): void
    {
        $basePath = storage_path('framework/testing/prompts');

        File::ensureDirectoryExists($basePath);
        File::put($basePath.'/test_prompt_v1.md', 'Hello {{ name }}.');

        config([
            'ai.prompts.base_path' => $basePath,
            'ai.prompts.files.test_prompt.v1' => 'test_prompt_v1.md',
        ]);

        $service = app(PromptBuilderService::class);

        $this->assertSame('Hello Sara.', $service->build('test_prompt', 'v1', ['name' => 'Sara']));
    }
}
