<?php

namespace App\Enums;

enum ClientStage: string
{
    case Intake = 'intake';
    case Analyzed = 'analyzed';
    case Chatting = 'chatting';
}
