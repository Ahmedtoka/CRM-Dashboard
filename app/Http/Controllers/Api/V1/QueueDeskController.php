<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\QueueDeskEndpoints;
use App\Http\Controllers\Controller;

/**
 * The moderator's queue desk on the mobile app (`/api/v1/queue/*`): the same endpoints and JSON
 * as the web `/queue/*` (`QueueDeskEndpoints`), behind the API's token/active/inbox-role middleware.
 */
class QueueDeskController extends Controller
{
    use QueueDeskEndpoints;
}
