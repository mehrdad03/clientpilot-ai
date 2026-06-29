<?php

return [
    'default_provider' => env('AI_PROVIDER', 'openai'),

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('OPENAI_MODEL', 'gpt-4.1'),
            'timeout' => (int) env('OPENAI_TIMEOUT', 30),
            'store_responses' => filter_var(env('OPENAI_STORE_RESPONSES', false), FILTER_VALIDATE_BOOL),
        ],
    ],

    'prompts' => [
        'base_path' => resource_path('prompts/sales-copilot'),
        'default_version' => 'v1',
        'files' => [
            'sales_copilot_analysis' => [
                'v1' => 'sales_copilot_analysis_v1.md',
            ],
            'sales_copilot_reply' => [
                'v1' => 'sales_copilot_reply_v1.md',
            ],
            'sales_copilot_feedback_review' => [
                'v1' => 'sales_copilot_feedback_review_v1.md',
            ],
            'sales_copilot_summary' => [
                'v1' => 'sales_copilot_summary_v1.md',
            ],
            'risk_guard' => [
                'v1' => 'risk_guard_v1.md',
            ],
        ],
    ],
];
