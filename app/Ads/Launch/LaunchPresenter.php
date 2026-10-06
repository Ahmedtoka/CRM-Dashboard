<?php

namespace App\Ads\Launch;

use App\Ads\Access\AdsScope;
use App\Ads\Control\PublishService;
use App\Ads\Control\Write\WriteLimits;
use App\Ads\Control\Write\WritePolicy;
use App\Ads\Materials\MaterialService;
use App\Ads\Platforms\AdPlatform;
use App\Models\Ad;
use App\Models\AdLaunch;
use App\Models\AdMaterialFile;
use App\Models\AdPublication;
use App\Models\AdsAuditLog;
use App\Models\User;

/** The one launch row every page and JSON answer uses (TS LaunchRow). */
final class LaunchPresenter
{
    public const WITH = ['material.files', 'material.product.variants', 'account', 'adSet.campaign', 'preparer:id,name', 'reviewer:id,name,user_id', 'forwarder:id,name', 'decider:id,name'];

    public function __construct(
        private readonly MaterialService $materials,
        private readonly LaunchChecks $checks,
        private readonly PublishService $publish,
        private readonly WriteLimits $limits,
        private readonly WritePolicy $policy,
        private readonly AdsScope $scope,
    ) {}

    /**
     * @param  array{checks?: false|'stored'|'fresh', publications?: bool, history?: bool}  $opts
     * @return array<string, mixed>
     */
    public function row(AdLaunch $l, User $viewer, array $opts = []): array
    {
        $l->loadMissing(self::WITH);
        $m = $l->material;
        $files = $m?->files->keyBy('id') ?? collect();
        $variants = $m?->product?->variants ?? collect();
        $mode = $opts['checks'] ?? false;
        $checks = null;
        $hash = $l->checks_hash;
        if ($mode === 'fresh') {
            $results = $this->checks->run($l, self::phaseFor($l), $viewer);
            $checks = array_map(fn (CheckResult $c) => $c->toArray(), $results);
            $hash = LaunchChecks::hash($l, $results);
        } elseif ($mode === 'stored') {
            $checks = $l->checks;
        }
        $iso = fn ($d) => $d?->toIso8601String();
        $person = fn ($u) => $u === null ? null : ['id' => $u->id, 'name' => $u->name];

        return [
            'id' => $l->public_id, 'state' => $l->state->value, 'hold_from' => $l->hold_from_state?->value, 'revision' => $l->revision, 'ads_count' => $l->adsCount(),
            'material' => $m === null ? null : ['id' => $m->id, 'title' => $m->title, 'thumb_url' => $m->files->first() ? $this->materials->fileRow($m->files->first())['thumb_url'] : null],
            'product' => $m?->product === null ? null : [
                'id' => $m->product->id, 'title' => $m->product->title, 'image_url' => $m->product->image_url,
                'prices' => $variants->map(fn ($v) => (float) $v->price)->unique()->sort()->values()->all(), 'inventory' => (int) $variants->sum('inventory_quantity'),
            ],
            'account' => $l->account === null ? null : ['id' => $l->account->id, 'name' => $l->account->name, 'platform' => $l->account->platform],
            'campaign' => ['external_id' => $l->campaign_external_id, 'name' => $l->campaign_name, 'status' => $l->adSet?->campaign?->status, 'objective' => $l->adSet?->campaign?->objective],
            'adset' => ['id' => $l->ad_set_id, 'external_id' => $l->adset_external_id, 'name' => $l->adset_name, 'status' => $l->adSet?->status],
            'identity' => $l->identity, 'link' => $l->link,
            'url_tags' => $l->account === null ? null : $this->publish->urlTags(AdPlatform::from($l->account->platform)),
            'file_ids' => array_map('intval', (array) $l->file_ids),
            'files' => collect((array) $l->file_ids)->map(fn ($id) => $files->get((int) $id))->filter()
                ->map(fn (AdMaterialFile $f) => $this->materials->fileRow($f) + ['width' => $f->width, 'height' => $f->height])->values()->all(),
            'captions' => array_values((array) $l->captions),
            'original' => $l->original,
            'people' => ['preparer' => $person($l->preparer), 'buyer' => $l->reviewer === null ? null : ['id' => $l->reviewer->id, 'name' => $l->reviewer->name],
                'forwarder' => $person($l->forwarder), 'decider' => $person($l->decider)],
            'dates' => [
                'created_at' => $iso($l->created_at), 'submitted_at' => $iso($l->submitted_at), 'forwarded_at' => $iso($l->forwarded_at), 'awaiting_at' => $iso($l->awaiting_at),
                'expires_at' => $iso($l->expires_at), 'decided_at' => $iso($l->decided_at), 'live_at' => $iso($l->live_at), 'stopped_at' => $iso($l->stopped_at),
            ],
            'decision' => $l->decision_code === null ? null : ['code' => $l->decision_code, 'reason' => $l->decision_reason],
            'last_error' => $l->last_error, 'self_approved' => (bool) $l->self_approved,
            'checks' => $checks, 'checks_hash' => $hash,
            'publications' => ($opts['publications'] ?? false) ? $this->publications($l) : null,
            'history' => ($opts['history'] ?? false) ? $this->history($l) : null,
            'money' => $this->scope->canSeeSpend($viewer) ? $this->money($l, $viewer) : null,
            'can' => $this->abilities($l, $viewer),
        ];
    }

    /** The checks a launch faces next: approve when it waits for the manager, forward under review, submit before. */
    public static function phaseFor(AdLaunch $l): string
    {
        return match (true) {
            $l->state === LaunchState::AwaitingApproval, $l->state === LaunchState::OnHold && $l->hold_from_state === LaunchState::AwaitingApproval => 'approve',
            $l->state === LaunchState::BuyerReview => 'forward',
            default => 'submit',
        };
    }

    /** @return list<array<string, mixed>> */
    private function publications(AdLaunch $l): array
    {
        $pubs = AdPublication::query()->where('ad_launch_id', $l->id)->orderBy('id')->get();
        $ads = Ad::query()->where('ad_account_id', $l->ad_account_id)->whereIn('external_id', $pubs->pluck('external_ad_id')->filter()->all())->get()->keyBy('external_id');
        $act = $l->account !== null ? preg_replace('/^act_/', '', (string) $l->account->external_id) : null;

        return $pubs->map(function (AdPublication $p) use ($ads, $act, $l) {
            $ad = $ads->get((string) $p->external_ad_id);

            return [
                'id' => $p->id, 'ad_name' => $p->ad_name, 'status' => $p->status, 'error' => $p->error, 'external_ad_id' => $p->external_ad_id,
                'archived' => $p->archived_at !== null, 'ad_status' => $ad?->status, 'effective_status' => $ad?->effective_status, 'preview_url' => $ad?->preview_url,
                'manager_url' => $p->external_ad_id !== null && $l->account?->platform === AdPlatform::Meta->value
                    ? 'https://adsmanager.facebook.com/adsmanager/manage/ads?act='.$act.'&selected_ad_ids='.$p->external_ad_id : null,
            ];
        })->values()->all();
    }

    /** @return list<array{action: string, at: ?string, actor: ?string, code: ?string, reason: ?string}> */
    private function history(AdLaunch $l): array
    {
        $rows = AdsAuditLog::query()->where('subject_type', 'AdLaunch')->where('subject_id', $l->id)->orderBy('id')->limit(100)->get();
        $names = User::query()->whereIn('id', $rows->pluck('actor_user_id')->filter()->unique()->all())->pluck('name', 'id');

        return $rows->map(fn (AdsAuditLog $r) => [
            'action' => (string) $r->action, 'at' => $r->at?->toIso8601String(), 'actor' => $names[$r->actor_user_id] ?? null,
            'code' => isset($r->meta['code']) ? (string) $r->meta['code'] : null, 'reason' => isset($r->meta['reason']) ? (string) $r->meta['reason'] : null,
        ])->values()->all();
    }

    /** @return array{cap: float, currency: string, parent_status: ?string, campaign_status: ?string} */
    private function money(AdLaunch $l, User $viewer): array
    {
        $limits = $this->limits->for($viewer, $l->account);

        return [
            'cap' => $limits['max_daily_budget_minor'] / 100, 'currency' => $limits['cap_currency'],
            'parent_status' => $l->adSet?->status, 'campaign_status' => $l->adSet?->campaign?->status,
        ];
    }

    /** @return array<string, bool> */
    private function abilities(AdLaunch $l, User $u): array
    {
        $s = $l->state;
        $draft = in_array($s, [LaunchState::Draft, LaunchState::ChangesRequested], true);
        $owner = LaunchPolicy::canEditDraft($u, $l);
        $reviewer = LaunchPolicy::isReviewer($u, $l);
        $approver = LaunchPolicy::canApprove($u);
        $self = in_array($u->id, array_values(array_filter([$l->prepared_by_id, $l->forwarded_by_id])), true) && ! $u->isAdmin();
        $waiting = $s === LaunchState::AwaitingApproval;

        return [
            'edit' => ($draft && $owner) || ($s === LaunchState::BuyerReview && $reviewer),
            'submit' => $draft && $owner,
            'send_back' => $s === LaunchState::BuyerReview && $reviewer,
            'forward' => $s === LaunchState::BuyerReview && $reviewer,
            'withdraw' => ($draft && $owner) || (in_array($s, [LaunchState::BuyerReview, LaunchState::CreateFailed], true) && $reviewer),
            'retry' => $s === LaunchState::CreateFailed && $reviewer,
            'approve' => $waiting && $approver && ! $self,
            'return' => $waiting && $approver,
            'reject' => $waiting && $approver,
            'stop' => in_array($s, [LaunchState::Launching, LaunchState::Live, LaunchState::Stopped], true) && $l->account !== null && $this->policy->inScope($u, $l->account),
            'retire' => in_array($s, [LaunchState::Live, LaunchState::Stopped], true) && $reviewer,
        ];
    }
}
