<?php

namespace App\Ads\Control;

use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdPublication;

/**
 * Attaches the ads the CRM created to their material (the same ad_material_ads link buyers edit by hand) as soon as the
 * sync has the ad locally. Each publication links once (linked_at), so an ad a buyer unlinks never comes back. A create
 * that timed out after the request went out is adopted by name and ad set (adoptOrphans, A6).
 */
final class PublicationLinker
{
    /** @return int publications linked */
    public function link(AdAccount $a): int
    {
        $linked = $this->adoptOrphans($a);
        $pending = AdPublication::query()->where('ad_account_id', $a->id)
            ->where('status', AdPublication::DONE)->whereNull('linked_at')->whereNotNull('external_ad_id')->get();
        if ($pending->isEmpty()) {
            return $linked;
        }

        $ads = Ad::query()->where('ad_account_id', $a->id)->whereIn('external_id', $pending->pluck('external_ad_id')->all())->pluck('id', 'external_id');
        foreach ($pending as $p) {
            $adId = $ads[$p->external_ad_id] ?? null;
            if ($adId === null) {
                continue;
            }
            $p->material?->ads()->syncWithoutDetaching([$adId]);
            $p->forceFill(['linked_at' => now()])->save();
            $linked++;
        }

        return $linked;
    }

    /**
     * A6: a publication whose ad-create request went out but never returned the id (ERROR, ad_requested_at set) is
     * matched to the synced ad by its deterministic name (Naming::adName) in the same ad set, one ad per publication
     * (oldest first) and never an ad another publication already holds. The adopted row becomes DONE with the id, so
     * the approval gate and the launch see the ad as theirs.
     */
    private function adoptOrphans(AdAccount $a): int
    {
        $orphans = AdPublication::query()->where('ad_account_id', $a->id)->where('status', AdPublication::ERROR)
            ->whereNull('external_ad_id')->whereNotNull('ad_requested_at')->orderBy('id')->get();
        if ($orphans->isEmpty()) {
            return 0;
        }

        $owned = AdPublication::query()->where('ad_account_id', $a->id)->whereNotNull('external_ad_id')->pluck('external_ad_id')->flip();
        $adopted = 0;
        foreach ($orphans as $p) {
            $ad = Ad::query()->where('ad_account_id', $a->id)->where('name', $p->ad_name)
                ->whereHas('adSet', fn ($q) => $q->where('external_id', $p->adset_external_id))
                ->orderBy('id')->get(['id', 'external_id'])
                ->first(fn (Ad $ad) => ! isset($owned[$ad->external_id]));
            if ($ad === null) {
                continue;
            }
            $owned[$ad->external_id] = true;
            $p->material?->ads()->syncWithoutDetaching([$ad->id]);
            $p->forceFill(['status' => AdPublication::DONE, 'external_ad_id' => $ad->external_id, 'error' => null, 'linked_at' => now()])->save();
            $adopted++;
        }

        return $adopted;
    }
}
