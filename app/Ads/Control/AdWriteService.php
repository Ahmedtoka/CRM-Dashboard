<?php

namespace App\Ads\Control;

use App\Ads\Access\AdsScope;
use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\SecretScrubber;
use App\Ads\Reports\AdsFilter;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAction;
use App\Models\AdCampaign;
use App\Models\AdSet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The single door for writes to an ad platform from the CRM (Stop / Run today): checks the user's scope, calls the
 * platform writer, logs every attempt in ad_actions (the platform call is audited before anything local is touched)
 * and mirrors the new status on the local row.
 *
 * Status rule: an ad's OWN status decides Stop / Run, normalised across platforms (ACTIVE/ENABLE = active,
 * PAUSED/DISABLE = paused); effective_status (which folds in the parents) is never used.
 */
final class AdWriteService
{
    public const LEVELS = ['campaign', 'adset', 'ad'];

    public const STATUSES = ['active', 'paused'];

    /** Platform spellings of a running / paused own status (Meta ACTIVE/PAUSED, TikTok ENABLE/DISABLE). */
    public const ACTIVE_STATUSES = ['ACTIVE', 'ENABLE'];

    public const PAUSED_STATUSES = ['PAUSED', 'DISABLE'];

    public function __construct(private readonly DriverFactory $drivers, private readonly AdsScope $scope) {}

    /** @return 'active'|'paused'|null null for anything else (archived, deleted, in review, unknown) */
    public static function statusKind(?string $status): ?string
    {
        $s = strtoupper((string) $status);

        return in_array($s, self::ACTIVE_STATUSES, true) ? 'active' : (in_array($s, self::PAUSED_STATUSES, true) ? 'paused' : null);
    }

    /** Admin/supervisor: any active account; media buyer: only the accounts assigned to their buyer today; nobody else. */
    public function canWrite(User $u, AdAccount $a): bool
    {
        return $this->canWriteMany($u, [$a])[$a->id];
    }

    /**
     * canWrite for many accounts with the buyer's assignments for today read once.
     *
     * @param  iterable<AdAccount>  $accounts
     * @return array<int, bool> account id => allowed
     */
    public function canWriteMany(User $u, iterable $accounts): array
    {
        $today = $this->todayIds($u);
        $out = [];
        foreach ($accounts as $a) {
            $out[$a->id] = (bool) $a->is_active && ($today === null || in_array($a->id, $today, true));
        }

        return $out;
    }

    /**
     * @param  'campaign'|'adset'|'ad'  $level
     * @param  'active'|'paused'  $status
     *
     * @throws AuthorizationException outside the user's scope
     * @throws ValidationException unknown target, nothing to change, or the platform refused (the message is readable)
     */
    public function setStatus(User $u, AdAccount $a, string $level, string $externalId, string $status, ?string $reason): AdAction
    {
        if (! $this->canWrite($u, $a)) {
            throw new AuthorizationException(__('ads.errors.out_of_scope'));
        }
        if (! in_array($level, self::LEVELS, true) || ! in_array($status, self::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => __('ads.errors.bad_request')]);
        }

        $row = $this->find($a, $level, $externalId);
        if ($row === null) {
            throw ValidationException::withMessages(['status' => __('ads.errors.not_found')]);
        }
        if (self::statusKind($row->status) === $status) {
            throw ValidationException::withMessages(['status' => __('ads.errors.already')]);
        }

        $to = strtoupper($status);
        $log = [
            'user_id' => $u->id, 'platform' => $a->platform, 'ad_account_id' => $a->id, 'account_name' => $a->name, 'level' => $level, 'external_id' => $externalId,
            'name' => mb_substr((string) $row->name, 0, 500),
            'from_status' => $row->status,
            'to_status' => $to, 'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
        ];

        try {
            $this->drivers->writer(AdPlatform::from($a->platform))->setStatus($a, $level, $externalId, $status);
        } catch (Throwable $e) {
            $known = $e instanceof AdsApiException;
            $raw = SecretScrubber::scrub($e->getMessage());
            AdAction::create($log + ['result' => AdAction::ERROR, 'error' => $raw !== '' ? $raw : $e::class]);
            if (! $known) {
                report($e);
            }

            $message = $e instanceof RateLimited ? __('ads.errors.rate_limited') : ($known && $raw !== '' ? $raw : __('ads.errors.failed'));

            throw ValidationException::withMessages(['status' => $message]);
        }

        // The platform changed: audit first, so a local failure below can never erase it.
        $action = AdAction::create($log + ['result' => AdAction::OK]);

        try {
            $row->forceFill(['status' => $to])->save(); // effective_status stays for the next sync
        } catch (Throwable $e) {
            report($e);
            $action->update(['error' => mb_substr('Local status not updated: '.SecretScrubber::scrub($e->getMessage()), 0, 1000)]);
        }

        return $action;
    }

    /** @return list<int>|null null = every account */
    private function todayIds(User $u): ?array
    {
        if ($u->isSupervisorOrAbove()) {
            return null;
        }
        if ($this->scope->buyerFor($u) === null) {
            return [];
        }

        $today = CarbonImmutable::now(AdsFilter::TIMEZONE)->startOfDay();

        return $this->scope->accountIds($u, $today, $today) ?? [];
    }

    private function find(AdAccount $a, string $level, string $externalId): Ad|AdSet|AdCampaign|null
    {
        return match ($level) {
            'campaign' => AdCampaign::where('ad_account_id', $a->id)->where('external_id', $externalId)->first(),
            'adset' => AdSet::where('external_id', $externalId)->whereHas('campaign', fn ($q) => $q->where('ad_account_id', $a->id))->first(),
            'ad' => Ad::where('ad_account_id', $a->id)->where('external_id', $externalId)->first(),
        };
    }
}
