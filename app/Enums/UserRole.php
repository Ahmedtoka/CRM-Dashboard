<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Supervisor = 'supervisor';
    case Moderator = 'moderator';
    case MediaBuyer = 'media_buyer';
    case Content = 'content';
}
