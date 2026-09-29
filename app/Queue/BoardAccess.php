<?php

namespace App\Queue;

use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\User;

/**
 * Who may open and run the live board: supervisors and admins, and the leader of a shift that
 * is open (whatever her role). While no shift is open the leaders named in the shift templates
 * are let in too, so the morning leader can start the day herself.
 */
class BoardAccess
{
    public static function allows(?User $user): bool
    {
        if ($user === null || ! $user->is_active) {
            return false;
        }

        if ($user->isSupervisorOrAbove()) {
            return true;
        }

        $leaders = Shift::query()->where('status', 'open')->pluck('leader_user_id');

        if ($leaders->isNotEmpty()) {
            return $leaders->contains(fn ($id) => $id !== null && (int) $id === (int) $user->id);
        }

        // A plain read: asking who may see a menu item never creates the settings row.
        $templates = QueueSetting::query()->find(1)?->shiftTemplates() ?? [];

        return collect($templates)->contains(fn (array $t) => ($t['leader_user_id'] ?? null) !== null && (int) $t['leader_user_id'] === (int) $user->id);
    }
}
