<?php

namespace App\Enums;

enum ConversationSource: string
{
    case Direct = 'direct';
    case Comment = 'comment';
    case Ad = 'ad';
}
