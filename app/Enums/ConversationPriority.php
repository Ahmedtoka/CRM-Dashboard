<?php

namespace App\Enums;

enum ConversationPriority: string
{
    case Normal = 'normal';
    case Low = 'low';
    case Spam = 'spam';
}
