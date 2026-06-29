<?php

namespace Tests\Unit;

use App\Services\Ai\AiJsonResponseValidator;
use PHPUnit\Framework\TestCase;

class AiJsonResponseValidatorTest extends TestCase
{
    public function test_it_accepts_valid_json_with_required_keys(): void
    {
        $validator = new AiJsonResponseValidator;

        $result = $validator->validate('{"summary":"Ready","score":5}', ['summary']);

        $this->assertTrue($result['valid']);
        $this->assertSame('Ready', $result['data']['summary']);
        $this->assertSame([], $result['errors']);
    }

    public function test_it_rejects_invalid_json(): void
    {
        $validator = new AiJsonResponseValidator;

        $result = $validator->validate('{invalid');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['data']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_it_reports_missing_required_keys(): void
    {
        $validator = new AiJsonResponseValidator;

        $result = $validator->validate('{"summary":"Ready"}', ['summary', 'next_step']);

        $this->assertFalse($result['valid']);
        $this->assertContains('Missing required key [next_step].', $result['errors']);
    }
}
