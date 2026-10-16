<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\QueueDeskEndpoints;
use App\Http\Controllers\Controller;

/**
 * The moderator's side of the handover queue inside the inbox (`/queue/*`). The endpoints live
 * in `QueueDeskEndpoints`, shared with the mobile app's `Api\V1\QueueDeskController`.
 */
class QueueWindowController extends Controller
{
    use QueueDeskEndpoints;
}
