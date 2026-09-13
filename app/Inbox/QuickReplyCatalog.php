<?php

namespace App\Inbox;

use App\Enums\Platform;
use App\Models\QuickReply;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Quick replies a user may use: those without a platform restriction plus those
 * for platforms the user can access.
 */
class QuickReplyCatalog
{
    /**
     * @return Collection<int, QuickReply>
     */
    public function for(User $u): Collection
    {
        return QuickReply::query()
            ->orderBy('shortcut')
            ->get()
            ->filter(function (QuickReply $qr) use ($u) {
                $platforms = $qr->platforms ?? [];

                if ($platforms === [] || $u->isSupervisorOrAbove()) {
                    return true;
                }

                foreach ($platforms as $value) {
                    $platform = Platform::tryFrom((string) $value);

                    if ($platform !== null && $u->canAccessPlatform($platform)) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(User $u): array
    {
        return $this->for($u)->map(fn (QuickReply $qr) => [
            'id' => $qr->id,
            'shortcut' => $qr->shortcut,
            'title' => $qr->title,
            'body' => $qr->body,
            'platforms' => $qr->platforms ?? [],
        ])->all();
    }
}
