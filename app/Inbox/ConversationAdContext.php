<?php

namespace App\Inbox;

use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The details panel's ad block (C 2.1, G5): the latest referral's ad (thumbnail, name, campaign,
 * ad set) and up to 10 earlier referrals. The link to the ad drawer is for ads-report roles only.
 */
final class ConversationAdContext
{
    public function for(Conversation $c, User $viewer): ?array
    {
        $refs = DB::table('conversation_ad_referrals')->where('conversation_id', $c->id)
            ->orderByDesc('referred_at')->limit(10)->get(['ad_external_id', 'referred_at']);

        if ($refs->isEmpty() && $c->ad_id === null) {
            return null;
        }

        $externals = $refs->pluck('ad_external_id')->push($c->ad_id)->filter()->unique()->values()->all();
        $ads = Ad::query()->with(['campaign:id,name', 'adSet:id,name'])->whereIn('external_id', $externals)
            ->orderBy('id')->get()->unique('external_id')->keyBy('external_id');

        $history = $refs->map(fn ($r) => [
            'ad_id' => $ads[$r->ad_external_id]->id ?? null,
            'external_id' => (string) $r->ad_external_id,
            'name' => $ads[$r->ad_external_id]->name ?? null,
            'thumbnail_url' => $ads[$r->ad_external_id]->thumbnail_url ?? null,
            'referred_at' => Carbon::parse($r->referred_at)->toIso8601String(),
        ])->values()->all();

        $currentExt = $refs->first()->ad_external_id ?? $c->ad_id;
        $ad = $currentExt !== null ? ($ads[$currentExt] ?? null) : null;
        $isFirst = $currentExt === $c->ad_id;
        $canReport = $viewer->isSupervisorOrAbove() || $viewer->role === UserRole::MediaBuyer;

        return [
            'ad_id' => $ad?->id,
            'external_id' => $currentExt,
            'name' => $ad?->name ?? ($isFirst ? ($c->ad_name ?? $c->ad_title) : null),
            'thumbnail_url' => ($isFirst ? $c->ad_photo_url : null) ?: $ad?->thumbnail_url,
            'campaign' => $ad?->campaign?->name ?? ($isFirst ? $c->ad_campaign_name : null),
            'adset' => $ad?->adSet?->name ?? ($isFirst ? $c->ad_adset_name : null),
            'referred_at' => $history[0]['referred_at'] ?? $c->ad_attributed_at?->toIso8601String(),
            'history' => $history,
            'can_open' => $ad !== null && $canReport,
        ];
    }
}
