<?php

namespace App\Shopify\Sync\Mappers;

enum MapResult: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Skipped = 'skipped_stale';
}
