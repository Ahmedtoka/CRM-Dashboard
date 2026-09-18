<?php

namespace App\Enums;

enum AttachmentStatus: string
{
    case Pending = 'pending';
    case Stored = 'stored';
    case Failed = 'failed';
}
