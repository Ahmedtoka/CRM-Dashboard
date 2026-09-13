<?php

namespace App\Enums;

enum OrderSource: string
{
    case Chat = 'chat';
    case Store = 'store';
}
