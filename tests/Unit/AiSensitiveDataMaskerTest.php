<?php

namespace Tests\Unit;

use App\Services\Ai\AiSensitiveDataMasker;
use PHPUnit\Framework\TestCase;

class AiSensitiveDataMaskerTest extends TestCase
{
    public function test_it_masks_sensitive_text_patterns(): void
    {
        $masker = new AiSensitiveDataMasker;

        $masked = $masker->maskText('Email test@example.com, phone +1 555 123 4567, api key=FAKE_OPENAI_KEY_FOR_MASKING_TEST, token=FAKE_TELEGRAM_TOKEN_FOR_MASKING_TEST, Bearer FAKE_BEARER_TOKEN_FOR_MASKING_TEST, password: FAKE_PASSWORD_FOR_MASKING_TEST.');

        $this->assertStringContainsString('[masked-email]', $masked);
        $this->assertStringContainsString('[masked-phone]', $masked);
        $this->assertStringContainsString('api key=[masked]', $masked);
        $this->assertStringContainsString('token=[masked]', $masked);
        $this->assertStringContainsString('Bearer [masked-token]', $masked);
        $this->assertStringContainsString('password=[masked]', $masked);
        $this->assertStringNotContainsString('test@example.com', $masked);
        $this->assertStringNotContainsString('FAKE_PASSWORD_FOR_MASKING_TEST', $masked);
    }

    public function test_it_masks_sensitive_array_keys_recursively(): void
    {
        $masker = new AiSensitiveDataMasker;

        $masked = $masker->maskArray([
            'api_key' => 'secret',
            'nested' => [
                'authorization' => 'Bearer secret',
                'message' => 'Contact user@example.com',
            ],
        ]);

        $this->assertSame('[masked]', $masked['api_key']);
        $this->assertSame('[masked]', $masked['nested']['authorization']);
        $this->assertSame('Contact [masked-email]', $masked['nested']['message']);
    }
}
