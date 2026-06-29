<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserFeedback extends Model
{
    protected $table = 'user_feedbacks';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'bot_suggestion_id',
        'replacement_bot_suggestion_id',
        'client_id',
        'ai_request_id',
        'telegram_user_id',
        'feedback_text',
        'ai_decision',
        'ai_reason',
        'result_action',
    ];
}
