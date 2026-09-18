<?php

namespace App\Http\Resources;

use App\Bot\HandoverSummary;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** @mixin Conversation */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Conversation $c */
        $c = $this->resource;
        $attributes = $c->getAttributes();

        // Filled by ConversationQuery::withListColumns(); fall back to a query for single rows.
        $preview = array_key_exists('last_message_body', $attributes)
            ? $attributes['last_message_body']
            : $c->messages()->orderByDesc('id')->value('body');

        $direction = array_key_exists('last_message_direction', $attributes)
            ? $attributes['last_message_direction']
            : $c->messages()->where('sender_type', '!=', SenderType::System->value)->orderByDesc('id')->value('direction');

        $direction = $direction instanceof MessageDirection ? $direction->value : $direction;

        $lockedBy = $c->locked_by_id !== null && $c->locked_until?->isFuture() ? $c->lockedBy : null;
        $firstResponder = $c->first_responder_id !== null ? $c->firstResponder : null;
        $customer = $c->customer;

        return [
            'id' => $c->id,
            'platform' => $c->platform?->value,
            'status' => $c->status?->value,
            'priority' => $c->priority?->value,
            'priority_level' => $c->priority_level,
            'queue' => $c->queue,
            'handover_category' => $c->handover_category,
            'handover_category_label' => self::categoryLabel($c),
            'handler' => $c->handler?->value,
            'needs_human' => (bool) $c->needs_human,
            'source' => $c->source?->value,
            'unread_count' => (int) $c->unread_count,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'last_customer_message_at' => $c->last_customer_message_at?->toIso8601String(),
            'waiting_since' => $direction === MessageDirection::In->value
                ? $c->last_customer_message_at?->toIso8601String()
                : null,
            'last_message_preview' => $preview !== null ? Str::limit((string) $preview, 80) : null,
            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'avatar_url' => $customer->avatar_url,
            ] : null,
            'locked_by' => $lockedBy ? ['id' => $lockedBy->id, 'name' => $lockedBy->name] : null,
            'first_responder' => $firstResponder ? ['id' => $firstResponder->id, 'name' => $firstResponder->name] : null,
            'tags' => $c->tags->map(fn (Tag $t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->values()->all(),
            'handling' => self::handling($c),
            // Fix round 1, minor (i): the claim button needs to know whether the
            // viewer may reply at all — computed here (not per row in the list
            // query) so a moderator scoped away from this platform never sees it.
            // `canAccessPlatform()` is O(1) per request: it short-circuits for
            // supervisor+, and for a moderator it reads the already-memoized
            // `userPlatforms` relation on the single, request-cached `$request->user()`
            // model — never a per-row query even across every row of the list.
            'can' => [
                'reply' => (bool) $request->user()?->canAccessPlatform($c->platform),
                'reset' => (bool) $request->user()?->isSupervisorOrAbove(),
            ],
        ];
    }

    /** Arabic handover category label from HandoverSummary, the one labels source (human bot flow Task 5). */
    public static function categoryLabel(Conversation $c): ?string
    {
        return $c->handover_category !== null && $c->handover_category !== ''
            ? HandoverSummary::categoryLabel((string) $c->handover_category)
            : null;
    }

    /**
     * Who's handling this conversation right now (spec §5.4, Task 15): an active
     * soft-lock holder first, else the last human whose reply is still recent.
     * Shared by the list/detail resource and the ConversationUpdated broadcast so
     * both resolve the same way without issuing a per-row query on the list.
     *
     * @return array{id:int,name:string,color:?string,via:string}|null
     */
    public static function handling(Conversation $c): ?array
    {
        if ($c->locked_by_id !== null && $c->locked_until?->isFuture()) {
            $u = $c->relationLoaded('lockedBy') ? $c->lockedBy : User::find($c->locked_by_id);

            return $u ? ['id' => $u->id, 'name' => $u->name, 'color' => $u->color, 'via' => 'lock'] : null;
        }

        if ($c->last_responder_id === null) {
            return null;
        }

        $attributes = $c->getAttributes();
        $lastReply = array_key_exists('last_human_reply_at', $attributes)
            ? $attributes['last_human_reply_at']
            : $c->messages()->where('sender_type', SenderType::User->value)->orderByDesc('id')->value('created_at');

        if ($lastReply === null || Carbon::parse($lastReply)->lt(now()->subMinutes((int) config('crm.handling_recent_minutes', 30)))) {
            return null;
        }

        $u = $c->relationLoaded('lastResponder') ? $c->lastResponder : User::find($c->last_responder_id);

        return $u ? ['id' => $u->id, 'name' => $u->name, 'color' => $u->color, 'via' => 'recent_reply'] : null;
    }
}
