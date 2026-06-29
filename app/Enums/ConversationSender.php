<?php

namespace App\Enums;

enum ConversationSender: string
{
    case Client = 'client';
    case Mehrdad = 'mehrdad';
    case User = 'user';
    case Bot = 'bot';
    case System = 'system';
}
