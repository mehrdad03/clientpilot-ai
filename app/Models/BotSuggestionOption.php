<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotSuggestionOption extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'bot_suggestion_id',
        'option_number',
        'label',
        'type',
        'body',
        'native_meaning',
    ];

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(BotSuggestion::class, 'bot_suggestion_id');
    }
}
