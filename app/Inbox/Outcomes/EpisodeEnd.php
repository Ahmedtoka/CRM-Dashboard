<?php

namespace App\Inbox\Outcomes;

/** Where an episode ended (see the S3 plan's Definitions). Stored in `conversation_outcomes.ended_by`. */
enum EpisodeEnd: string
{
    case Close = 'close';
    case AutoClose = 'auto_close';
    case Resolve = 'resolve';
    case ApiResolve = 'api_resolve';
    case Idle = 'idle';
}
