<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ConversationContextEndpoint;
use App\Http\Controllers\Concerns\ConversationEndpoints;
use App\Http\Controllers\Controller;

class ConversationController extends Controller
{
    use ConversationContextEndpoint;
    use ConversationEndpoints;
}
