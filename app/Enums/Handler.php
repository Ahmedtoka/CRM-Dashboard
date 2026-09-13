<?php

namespace App\Enums;

enum Handler: string
{
    case Bot = 'bot';
    case Human = 'human';
}
