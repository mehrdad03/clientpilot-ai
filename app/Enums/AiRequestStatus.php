<?php

namespace App\Enums;

enum AiRequestStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case ValidationFailed = 'validation_failed';
}
