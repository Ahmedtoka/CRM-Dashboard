<?php

namespace App\Enums;

enum CommentStatus: string
{
    case New = 'new';
    case Replied = 'replied';
    case Hidden = 'hidden';
    case Ignored = 'ignored';
}
