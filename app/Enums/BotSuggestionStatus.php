<?php

namespace App\Enums;

enum BotSuggestionStatus: string
{
    case Generated = 'generated';
    case Selected = 'selected';
    case Rejected = 'rejected';
    case Regenerated = 'regenerated';
    case Ignored = 'ignored';
    case Stale = 'stale';
}
