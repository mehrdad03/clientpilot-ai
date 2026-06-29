<?php

namespace App\Enums;

enum UserFeedbackDecision: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case PartiallyAccepted = 'partially_accepted';
}
