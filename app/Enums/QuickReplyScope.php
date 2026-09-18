<?php

namespace App\Enums;

enum QuickReplyScope: string
{
    case Shared = 'shared';
    case Personal = 'personal';
}
