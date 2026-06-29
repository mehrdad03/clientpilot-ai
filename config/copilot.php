<?php

return [
    'native_language' => strtolower(trim((string) env('NATIVE_LANGUAGE', 'fa'))) ?: 'fa',
    'target_language' => strtolower(trim((string) env('TARGET_LANGUAGE', 'en'))) ?: 'en',
    'target_platform_name' => trim((string) env('TARGET_PLATFORM_NAME', 'FreelanceHub')) ?: 'FreelanceHub',
];
