<?php

namespace App\Enums;

enum ConversationMessageType: string
{
    case InitialJob = 'initial_job';
    case ClientMessage = 'client_message';
    case SelectedReply = 'selected_reply';
    case CustomReply = 'custom_reply';
    case BotAnalysis = 'bot_analysis';
    case Note = 'note';
}
