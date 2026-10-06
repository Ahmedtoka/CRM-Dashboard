<?php

namespace App\Ads\Launch;

use App\Models\AdLaunch;
use App\Models\AdMaterial;

/**
 * The material's status, derived (fixes C1/C2): from its launches and its linked ads' own status, never a click.
 * new (جديدة) · in_review (في المراجعة) · live (شغالة) · paused (واقفة) · retired (خلصت).
 */
final class MaterialStatus
{
    public const VALUES = ['new', 'in_review', 'live', 'paused', 'retired'];

    public static function derive(AdMaterial $m): string
    {
        $states = AdLaunch::query()->where('ad_material_id', $m->id)->get(['state'])
            ->map(fn (AdLaunch $l) => $l->state->value)->all();
        $runningAd = $m->ads()->whereRaw("UPPER(ads.status) = 'ACTIVE'")->exists();
        $preLive = array_intersect($states, [...LaunchState::HOLDABLE_VALUES, 'on_hold']) !== [];

        return match (true) {
            $runningAd || array_intersect($states, ['live', 'launching']) !== [] => 'live',
            $preLive => 'in_review',
            in_array('stopped', $states, true) => 'paused',
            $m->status === 'retired' || in_array('retired', $states, true) => 'retired',
            default => 'new',
        };
    }

    public function refresh(AdMaterial $m): string
    {
        $status = self::derive($m);
        if ($status !== $m->status) {
            $m->forceFill(array_merge(
                ['status' => $status],
                $status === 'live' && $m->activated_at === null ? ['activated_at' => now()] : [],
                $status !== 'live' ? ['need_stop_at' => null] : [],
            ))->save();
        }

        return $status;
    }

    /** LaunchMoved listener. */
    public function handle(LaunchMoved $e): void
    {
        $m = $e->launch->material()->first();
        if ($m !== null) {
            $this->refresh($m);
        }
    }
}
