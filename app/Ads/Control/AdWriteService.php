<?php

namespace App\Ads\Control;

use App\Ads\Access\AdsScope;
use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\RateLimited;
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

/**
 * The single door for writes to an ad platform from the CRM (Stop / Run today): checks the user's scope, calls the
 * platform writer, mirrors the new status on the local row and logs every attempt in ad_actions.
 */
final class AdWriteService
{
    public const LEVELS = ['campaign', 'adset', 'ad'];

    public const STATUSES = ['active', 'paused'];

    public function __construct(private readonly DriverFactory $drivers, private readonly AdsScope $scope) {}

    /** Admin/supervisor: any active account; media buyer: only the accounts assigned to their buyer today; nobody else. */
    public function canWrite(User $u, AdAccount $a): bool
    {
        if (! $a->is_active) {
            return false;
        }
        if ($u->isSupervisorOrAbove()) {
            return true;
        }
        if ($this->scope->buyerFor($u) === null) {
            return false;
        }

        $today = CarbonImmutable::now(AdsFilter::TIMEZONE)->startOfDay();

        return in_array($a->id, $this->scope->accountIds($u, $today, $today) ?? [], true);
    }

    /**
     * @param  'campaign'|'adset'|'ad'  $level
     * @param  'active'|'paused'  $status
     *
     * @throws AuthorizationException outside the user's scope
     * @throws ValidationException unknown target, or the platform refused (the message is readable)
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

        $to = strtoupper($status);
        $log = [
            'user_id' => $u->id, 'platform' => $a->platform, 'ad_account_id' => $a->id, 'level' => $level, 'external_id' => $externalId,
            'name' => mb_substr((string) $row->name, 0, 500),
            'from_status' => $level === 'ad' ? ($row->effective_status ?? $row->status) : $row->status,
            'to_status' => $to, 'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
        ];

        try {
            $this->drivers->writer(AdPlatform::from($a->platform))->setStatus($a, $level, $externalId, $status);
        } catch (AdsApiException $e) {
            $message = $e instanceof RateLimited ? __('ads.errors.rate_limited') : $e->getMessage();
            AdAction::create($log + ['result' => AdAction::ERROR, 'error' => $message]);

            throw ValidationException::withMessages(['status' => $message !== '' ? $message : __('ads.errors.failed')]);
        }

        $row->forceFill($level === 'ad' ? ['status' => $to, 'effective_status' => $to] : ['status' => $to])->save();

        return AdAction::create($log + ['result' => AdAction::OK]);
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
