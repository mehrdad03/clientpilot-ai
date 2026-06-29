<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BotSuggestion extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'conversation_message_id',
        'ai_request_id',
        'telegram_user_id',
        'client_read',
        'best_move',
        'risk_level',
        'risk_reason',
        'detected_intent',
        'next_stage',
        'status',
        'selected_option_id',
        'selected_text',
        'selected_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selected_at' => 'datetime',
        ];
    }

    public function options(): HasMany
    {
        return $this->hasMany(BotSuggestionOption::class);
    }
}
