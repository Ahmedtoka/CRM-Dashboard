<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ConversationEndpoints;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Inbox\ConversationQuery;
use App\Inbox\QuickReplyCatalog;
use App\Models\City;
use App\Models\Tag;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    use ConversationEndpoints;

    public function index(Request $request, ConversationQuery $query, QuickReplyCatalog $quickReplies): Response
    {
        $filters = $this->conversationFilters($request);
        $user = $request->user();

        return Inertia::render('Inbox', [
            'conversations' => ConversationResource::collection($query->paginate($user, $filters)),
            'filters' => array_merge(['platform' => null, 'status' => null, 'filter' => null, 'q' => null], $filters),
            'quickReplies' => $quickReplies->toArray($user),
            'tags' => Tag::orderBy('name')->get(['id', 'name', 'color']),
            'cities' => City::orderBy('name_ar')->get(['id', 'name_ar', 'name_en', 'shipping_fee']),
        ]);
    }
}
