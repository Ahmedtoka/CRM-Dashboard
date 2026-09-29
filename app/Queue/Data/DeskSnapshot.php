<?php

namespace App\Queue\Data;

use App\Models\QueueSetting;
use App\Models\ShiftMember;
use Illuminate\Support\Collection;

/**
 * The desks as the wait estimate sees them, read once per tick (WaitEstimator::snapshot()) so the
 * estimate of each waiting customer costs no query of its own.
 */
final class DeskSnapshot
{
    /**
     * @param  Collection<int, ShiftMember>  $members  serving desks (available / busy) of the open shift(s), `user.userPlatforms` loaded
     * @param  array<int, list<int>>  $spent  per shift-member id: seconds each of her open windows has run so far
     * @param  list<int>  $leaderIds  user ids leading an open shift (their desk serves escalations only)
     * @param  array<int, int>  $positions  waiting entry id => 1-based place among the live waiting customers
     */
    public function __construct(
        public readonly QueueSetting $settings,
        public readonly Collection $members,
        public readonly array $spent,
        public readonly int $avg,
        public readonly array $leaderIds,
        public readonly array $positions = [],
    ) {}
}
