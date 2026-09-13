<?php

namespace App\Enums;

enum SenderType: string
{
    case Customer = 'customer';
    case User = 'user';
    case Bot = 'bot';
    case System = 'system';
}
