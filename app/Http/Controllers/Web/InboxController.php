<?php

namespace App\Http\Controllers\Web;

use App\Enums\UserRole;
use App\Http\Controllers\Concerns\ConversationEndpoints;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Inbox\ConversationQuery;
use App\Inbox\QuickReplyCatalog;
use App\Models\City;
use App\Models\QueueSetting;
use App\Models\QuickReplyCategory;
use App\Models\Tag;
use App\Models\User;
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
            'conversations' => ConversationResource::collection($query->paginate($user, $filters))->additional(['search_mode' => $query->searchMode(), 'meta' => ['search_truncated' => $query->searchTruncated()]]),
            // An old single `filter=` link arrives here already mapped into `flags` (R4).
            'filters' => array_merge(
                ['platform' => null, 'status' => null, 'queue' => null, 'assignee' => null, 'flags' => [], 'q' => null, 'tag' => null],
                $filters,
            ),
            // The moderator filter's options (spec §1.2): active moderators and supervisors, by name.
            'moderators' => User::query()->where('is_active', true)
                ->whereIn('role', [UserRole::Moderator->value, UserRole::Supervisor->value])
                ->orderBy('name')->get(['id', 'name', 'color'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'color' => $u->color])->values(),
            'queueEnabled' => (bool) QueueSetting::current()->enabled,
            'quickReplies' => $quickReplies->toArray($user),
            'quickReplyCategories' => QuickReplyCategory::orderBy('sort')->get(['id', 'name', 'sort']),
            'tags' => Tag::orderBy('name')->get(['id', 'name', 'color']),
            'cities' => City::orderBy('name_ar')->get(['id', 'name_ar', 'name_en', 'shipping_fee']),
        ]);
    }
}
