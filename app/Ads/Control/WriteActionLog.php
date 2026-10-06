<?php

namespace App\Ads\Control;

use App\Ads\Access\AdsScope;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Platforms\SecretScrubber;
use App\Http\Controllers\Web\Ads\WriteActionController;
use App\Models\AdWriteAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

/** The Stop/Run log (old Actions page, now «محتاج قرار» › السجل and the drawer history), scoped like every report. */
final class WriteActionLog
{
    public const LIMIT = 100;

    public function rows(User $u, array $filters = [], int $limit = self::LIMIT): array
    {
        $allowed = app(AdsScope::class)->accountIds($u);
        $result = $filters['result'] ?? null;

        $actions = AdWriteAction::query()->with(['confirmer:id,name', 'proposer:id,name', 'account:id,name'])
            ->whereNotIn('state', [AdWriteAction::PROPOSED, AdWriteAction::EXPIRED, AdWriteAction::CANCELLED, AdWriteAction::SUPERSEDED])
            ->when($allowed !== null, fn ($q) => $q->whereIn('ad_account_id', $allowed))
            ->when(is_int($filters['user'] ?? null), fn ($q) => $q->where(fn ($w) => $w->where('confirmed_by_id', $filters['user'])->orWhere(fn ($p) => $p->whereNull('confirmed_by_id')->where('proposed_by_id', $filters['user']))))
            ->when(in_array($filters['level'] ?? null, AdWriteService::LEVELS, true), fn ($q) => $q->where('target_level', $filters['level']))
            ->when($result === 'ok', fn ($q) => $q->whereIn('state', [AdWriteAction::SUCCEEDED, AdWriteAction::ROLLED_BACK]))
            ->when($result === 'pending', fn ($q) => $q->whereIn('state', [AdWriteAction::EXECUTING, AdWriteAction::UNKNOWN]))
            ->when($result === 'error', fn ($q) => $q->whereNotIn('state', [AdWriteAction::SUCCEEDED, AdWriteAction::ROLLED_BACK, AdWriteAction::EXECUTING, AdWriteAction::UNKNOWN]))
            ->when(isset($filters['ad']), fn ($q) => $q->where('target_level', 'ad')->where('ad_account_id', $filters['ad']['account_id'])->where('target_external_id', $filters['ad']['external_id']))
            ->orderByRaw('COALESCE(confirmed_at, created_at) DESC')->orderByDesc('id')->limit($limit)->get();
        // Ad-level rows open the ad drawer: one lookup for the whole list.
        $adIds = DB::table('ads')->whereIn('external_id', $actions->where('target_level', 'ad')->pluck('target_external_id')->unique()->values()->all())
            ->get(['id', 'ad_account_id', 'external_id'])->mapWithKeys(fn (object $r) => [$r->ad_account_id.'|'.$r->external_id => (int) $r->id]);

        return $actions->map(fn (AdWriteAction $a) => [
            'id' => $a->id, 'at' => ($a->confirmed_at ?? $a->created_at)?->toIso8601String(),
            'ad_id' => $a->target_level === 'ad' ? ($adIds[$a->ad_account_id.'|'.$a->target_external_id] ?? null) : null,
            'user' => ($a->confirmer ?? $a->proposer)?->name, 'user_id' => $a->confirmed_by_id ?? $a->proposed_by_id,
            'platform' => $a->platform, 'account' => (string) ($a->account?->name ?? $a->account_name ?? ''), 'account_id' => (int) $a->ad_account_id,
            'level' => $a->target_level, 'name' => (string) $a->target_name, 'external_id' => (string) $a->target_external_id,
            'from_status' => $a->from_status, 'to_status' => $a->to_status === 'active' ? 'ACTIVE' : 'PAUSED',
            'reason' => $a->reason, 'result' => self::result($a->state), 'error' => self::error($a), 'source' => (string) $a->source,
        ])->all();
    }

    /** @return 'ok'|'error'|'pending' */
    public static function result(string $state): string
    {
        return match ($state) {
            AdWriteAction::SUCCEEDED, AdWriteAction::ROLLED_BACK => 'ok',
            AdWriteAction::EXECUTING, AdWriteAction::UNKNOWN => 'pending',
            default => 'error',
        };
    }

    public static function error(AdWriteAction $a): ?string
    {
        if (in_array($a->state, [AdWriteAction::SUCCEEDED, AdWriteAction::ROLLED_BACK], true)) {
            return null;
        }
        $code = (string) $a->error_code;
        if ($code !== '' && Lang::has('ads.errors.'.$code)) {
            $details = WriteActionController::storedDetails($a);

            return WriteDenied::withPlatformMessage(WriteDenied::messageFor($code, $details), $details['platform_message'] ?? null);
        }

        return $a->error_message !== null ? SecretScrubber::scrub($a->error_message) : null;
    }
}
