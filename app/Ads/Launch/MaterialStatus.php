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
            // Was live once (final review B1): a remapped or owner-activated material, or one whose linked ads all
            // stopped, is paused, never back to new.
            $m->activated_at !== null || in_array($m->status, ['live', 'paused'], true) => 'paused',
            default => 'new',
        };
    }

    public function refresh(AdMaterial $m): string
    {
        $status = self::derive($m);
        if ($status !== $m->status) {
            $m->forceFill(array_merge(
                ['status' => $status],
                // Stamped only when an ad really ran: a launch that is merely launching (and may come back unrun,
                // then be rejected) must not make the material «واقفة» forever (MORNING-REPORT 6-a).
                $status === 'live' && $m->activated_at === null && self::ran($m) ? ['activated_at' => now()] : [],
                $status !== 'live' ? ['need_stop_at' => null] : [],
            ))->save();
        }

        return $status;
    }

    /** An ad of the material is running, or one of its launches is live. */
    private static function ran(AdMaterial $m): bool
    {
        return $m->ads()->whereRaw("UPPER(ads.status) = 'ACTIVE'")->exists()
            || AdLaunch::query()->where('ad_material_id', $m->id)->where('state', LaunchState::Live->value)->exists();
    }

    /**
     * One pass over every material that may have drifted (final review B1): stored live / paused / new. Catches a
     * live material with no linked ad left (the per-account sync pass never sees it). Run hourly by ads:launch-sweep;
     * run once by hand after the status remap migration.
     *
     * @return int how many changed
     */
    public function refreshAll(): int
    {
        $changed = 0;
        AdMaterial::query()->whereIn('status', ['live', 'paused', 'new'])->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$changed) {
                foreach ($chunk as $m) {
                    $before = $m->status;
                    if ($this->refresh($m) !== $before) {
                        $changed++;
                    }
                }
            });

        return $changed;
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
