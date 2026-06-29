<?php

namespace Tests\Feature;

use App\Enums\AiRequestStatus;
use App\Models\AiRequest;
use App\Services\Ai\AiRequestLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiRequestLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_logs_masked_ai_request_lifecycle(): void
    {
        $logger = app(AiRequestLogger::class);

        $request = $logger->createPending(
            provider: 'openai',
            model: 'gpt-4.1',
            promptKey: 'sales_copilot_analysis',
            promptVersion: 'v1',
            requestPayload: [
                'input' => 'Contact user@example.com',
                'api_key' => 'secret',
            ],
            metadata: [
                'source' => 'test',
            ],
        );

        $this->assertInstanceOf(AiRequest::class, $request);
        $this->assertSame(AiRequestStatus::Pending->value, $request->status);
        $this->assertSame('Contact [masked-email]', $request->request_payload['input']);
        $this->assertSame('[masked]', $request->request_payload['api_key']);

        $sent = $logger->markSent($request, queueName: 'future-ai', jobClass: 'FutureAiJob', jobUuid: 'job-1');

        $this->assertSame(AiRequestStatus::Sent->value, $sent->status);
        $this->assertSame('future-ai', $sent->queue_name);
        $this->assertSame('FutureAiJob', $sent->job_class);
        $this->assertSame('job-1', $sent->job_uuid);
        $this->assertNotNull($sent->started_at);

        $succeeded = $logger->markSucceeded($sent, ['output' => 'Ready'], 1200);

        $this->assertSame(AiRequestStatus::Succeeded->value, $succeeded->status);
        $this->assertSame(['output' => 'Ready'], $succeeded->response_payload);
        $this->assertSame(1200, $succeeded->duration_ms);
        $this->assertNotNull($succeeded->completed_at);
    }
}
