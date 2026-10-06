<?php

namespace App\Ads\Launch;

use App\Ads\Control\Write\WriteDenied;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdLaunch;
use App\Models\AdMaterial;
use App\Models\AdPublication;

/**
 * After a sync (and after a CRM Stop): launching / live / stopped launches follow their ads' own status. T8 when an unknown
 * Run turns out ACTIVE (E8), T13 all ads paused, T14 an approved ad running again. Never throws into the sync.
 */
final class LaunchMonitor
{
    public function __construct(private readonly LaunchService $launches, private readonly LaunchNotifier $notify) {}

    public function afterSync(AdAccount $a): int
    {
        $moved = 0;
        foreach (AdLaunch::query()->where('ad_account_id', $a->id)->whereIn('state', ['launching', 'live', 'stopped'])->get() as $l) {
            if ($this->follow($l)) {
                $moved++;
            }
        }

        $status = app(MaterialStatus::class);
        AdMaterial::query()->whereIn('status', ['live', 'paused', 'new'])
            ->whereHas('ads', fn ($q) => $q->where('ads.ad_account_id', $a->id))->get()
            ->each(fn (AdMaterial $m) => $status->refresh($m));

        return $moved;
    }

    public function follow(AdLaunch $l): bool
    {
        $l->refresh();
        $ext = AdPublication::query()->where('ad_launch_id', $l->id)->whereNull('archived_at')->whereNotNull('external_ad_id')->pluck('external_ad_id');
        if ($ext->isEmpty()) {
            return false;
        }
        $statuses = Ad::query()->where('ad_account_id', $l->ad_account_id)->whereIn('external_id', $ext->all())->pluck('status')
            ->map(fn ($s) => strtoupper((string) $s));
        if ($statuses->isEmpty()) {
            return false;
        }
        $active = $statuses->contains('ACTIVE');
        try {
            if ($active && in_array($l->state, [LaunchState::Launching, LaunchState::Stopped], true)) {
                $from = $l->state;
                $l = $this->launches->transition($l, [$from], LaunchState::Live, ['live_at' => $l->live_at ?? now(), 'stopped_at' => null, 'last_error' => null], null, null, ['by' => 'sync']);
                if ($from === LaunchState::Launching) {
                    $this->notify->live($l);
                }

                return true;
            }
            if (! $active && $l->state === LaunchState::Live) {
                $this->launches->transition($l, [LaunchState::Live], LaunchState::Stopped, ['stopped_at' => now()], null, null, ['by' => 'sync']);

                return true;
            }
        } catch (WriteDenied) {
            return false; // moved meanwhile
        }

        return false;
    }
}
