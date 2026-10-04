<?php

namespace App\Ads\Control;

use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdPublication;

/**
 * Attaches the ads the CRM created to their material (the same ad_material_ads link buyers edit by hand) as soon as the
 * sync has the ad locally. Each publication links once (linked_at), so an ad a buyer unlinks never comes back.
 */
final class PublicationLinker
{
    /** @return int publications linked */
    public function link(AdAccount $a): int
    {
        $pending = AdPublication::query()->where('ad_account_id', $a->id)
            ->where('status', AdPublication::DONE)->whereNull('linked_at')->whereNotNull('external_ad_id')->get();
        if ($pending->isEmpty()) {
            return 0;
        }

        $ads = Ad::query()->where('ad_account_id', $a->id)->whereIn('external_id', $pending->pluck('external_ad_id')->all())->pluck('id', 'external_id');
        $linked = 0;
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
}
