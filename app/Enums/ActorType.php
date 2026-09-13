<?php

namespace App\Enums;

enum ActorType: string
{
    case User = 'user';
    case Bot = 'bot';
    case System = 'system';
}
