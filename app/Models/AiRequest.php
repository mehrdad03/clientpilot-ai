<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiRequest extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'model',
        'prompt_key',
        'prompt_version',
        'status',
        'request_payload',
        'response_payload',
        'metadata',
        'duration_ms',
        'queue_name',
        'job_class',
        'job_uuid',
        'scheduled_at',
        'queued_at',
        'started_at',
        'completed_at',
        'failed_at',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'metadata' => 'array',
            'scheduled_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
