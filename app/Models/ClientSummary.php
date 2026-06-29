<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientSummary extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'telegram_user_id',
        'ai_request_id',
        'summary',
        'current_context',
        'what_client_wants',
        'what_mehrdad_promised',
        'pricing_discussed',
        'deadline_discussed',
        'access_needed',
        'open_questions',
        'risk_notes',
        'next_best_move',
        'last_message_id',
    ];
}
