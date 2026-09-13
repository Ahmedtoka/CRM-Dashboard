<?php

namespace App\Enums;

enum ParticipantRole: string
{
    case First = 'first';
    case Continued = 'continued';
    case FollowUp = 'follow_up';
}
