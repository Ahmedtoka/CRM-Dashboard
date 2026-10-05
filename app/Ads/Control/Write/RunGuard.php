<?php

namespace App\Ads\Control\Write;

use App\Ads\Platforms\Data\ObjectState;
use App\Models\AdAccount;
use App\Models\AdWriteAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The Run guard (B3): live pre-read, budget cap, activation caps, restart lock. Batch 2 ships the seam only: both hooks
 * pass everything through. Batch 3 (tasks 14-16) fills them in. Stop never reaches this class (2.1 rule 5).
 */
class RunGuard
{
    /**
     * Called for a Run at propose, after the policy and the target lookup.
     *
     * @return array{0: ?ObjectState, 1: array<string, mixed>, 2: array<int|string, mixed>} [live read, limits checked, notes]
     *
     * @throws WriteDenied
     */
    public function atPropose(User $u, AdAccount $a, Model $target): array
    {
        return [null, [], []];
    }

    /**
     * Called for a Run at confirm, before the claim. A refusal moves the action proposed → failed.
     *
     * @throws WriteDenied
     */
    public function atConfirm(User $u, AdWriteAction $x): void {}
}
