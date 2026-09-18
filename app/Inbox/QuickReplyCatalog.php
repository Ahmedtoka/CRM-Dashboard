<?php

namespace App\Inbox;

use App\Enums\AttachmentType;
use App\Enums\Platform;
use App\Models\QuickReply;
use App\Models\QuickReplyAttachment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Quick replies a user may use (spec §2.1): shared replies plus the user's
 * own personal replies, filtered by platform — an empty platforms list on a
 * reply means all platforms. Personal replies sort first.
 */
class QuickReplyCatalog
{
    /**
     * @return Collection<int, QuickReply>
     */
    public function for(User $u, ?Platform $platform = null): Collection
    {
        // An explicit platform the user has no access to (e.g. a moderator
        // passing ?platform= for a platform outside their assignment) never
        // leaks that platform's replies — an empty list, not an error.
        if ($platform !== null && ! $u->canAccessPlatform($platform)) {
            return collect();
        }

        return QuickReply::query()->usableBy($u)->with(['category:id,name', 'attachments'])
            ->orderByRaw("case when scope = 'personal' then 0 else 1 end")->orderBy('shortcut')->get()
            ->filter(function (QuickReply $qr) use ($u, $platform) {
                $platforms = $qr->platforms ?? [];
                if ($platforms === []) {
                    return true;
                }
                if ($platform !== null) {
                    return in_array($platform->value, $platforms, true);
                }
                if ($u->isSupervisorOrAbove()) {
                    return true;
                }
                foreach ($platforms as $value) {
                    $p = Platform::tryFrom((string) $value);
                    if ($p !== null && $u->canAccessPlatform($p)) {
                        return true;
                    }
                }

                return false;
            })->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(User $u, ?Platform $platform = null): array
    {
        return $this->for($u, $platform)->map(fn (QuickReply $qr) => [
            'id' => $qr->id, 'shortcut' => $qr->shortcut, 'title' => $qr->title, 'body' => $qr->body,
            'platforms' => $qr->platforms ?? [], 'scope' => $qr->scope->value, 'user_id' => $qr->user_id,
            'category' => $qr->category ? ['id' => $qr->category->id, 'name' => $qr->category->name] : null,
            'use_count' => (int) $qr->use_count,
            'attachments' => $qr->attachments->map(fn (QuickReplyAttachment $a) => [
                'id' => $a->id, 'type' => $a->type->value, 'original_name' => $a->original_name,
                'thumb_url' => $a->type === AttachmentType::Image ? route('quick-reply-attachments.show', $a, false) : null,
            ])->all(),
        ])->all();
    }
}
