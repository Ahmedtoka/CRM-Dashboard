<?php

namespace App\Ads\Launch;

use App\Ads\AdsSettings;
use App\Ads\Audit\AdsAudit;
use App\Ads\Control\DuplicatePublication;
use App\Ads\Control\Jobs\PublishAd;
use App\Ads\Control\PublishService;
use App\Ads\Control\Write\WriteActionService;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Control\Write\WritePolicy;
use App\Ads\Control\Write\WriteSwitch;
use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\WriteGuard;
use App\Ads\Platforms\WriteRefused;
use App\Models\AdLaunch;
use App\Models\AdMaterial;
use App\Models\AdPublication;
use App\Models\AdSet;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Every launch transition (L 2.2 with the 2026-10-06 overrides). Each one is one compare-and-set on ad_launches.state
 * (and revision when the caller saw one): a lost race is a 409 (launch_state / launch_changed), never a double move.
 * Every move is audited (launch.<state>) and fires LaunchMoved.
 */
final class LaunchService
{
    public const REASONS = ['caption_wrong', 'price_wrong', 'media_quality', 'wrong_adset', 'off_brand', 'out_of_stock', 'other'];

    public const SYSTEM_REASONS = ['slot_closed'];

    public function __construct(
        private readonly LaunchChecks $checks,
        private readonly LaunchNotifier $notify,
        private readonly PublishService $publish,
        private readonly LaunchSettings $settings,
        private readonly AdsSettings $adsSettings,
        private readonly DriverFactory $drivers,
        private readonly WriteActionService $writes,
        private readonly WritePolicy $policy,
    ) {}

    /**
     * T1: a draft on an open slot (D1). Content chooses the slot, files, captions and CTA only (2.3).
     *
     * @param  array{adset_id:int|string, file_ids:list<int|string>, captions:list<array{headline:string, primary_text:string, cta:string}>}  $data
     *
     * @throws ValidationException|WriteDenied
     */
    public function create(User $by, AdMaterial $m, array $data): AdLaunch
    {
        if (! LaunchPolicy::canPrepare($by)) {
            throw WriteDenied::make('launch_forbidden');
        }
        $m->loadMissing(['product', 'files']);
        if ($m->product === null || $m->files->isEmpty()) {
            throw ValidationException::withMessages(['material' => __('ads.launch.errors.material_not_ready')]);
        }
        $slot = $this->openSlot((int) $data['adset_id']);
        $l = AdLaunch::create($this->slotAttributes($slot) + [
            'ad_material_id' => $m->id, 'link' => $this->publish->productLink($m),
            'file_ids' => $this->materialFiles($m, $data['file_ids']), 'captions' => self::cleanCaptions($data['captions']),
            'state' => LaunchState::Draft, 'prepared_by_id' => $by->id,
        ]);
        AdsAudit::record('launch.created', $l, null, ['state' => 'draft'], ['public_id' => $l->public_id, 'material_id' => $m->id, 'ad_set_id' => $slot->id], $by);
        event(new LaunchMoved($l, null, LaunchState::Draft));

        return $l->refresh();
    }

    /**
     * Content edits a draft (draft / changes_requested); the reviewing buyer edits in review (T4: diff audited, identity
     * allowed). Every edit bumps the revision and clears the stored checks.
     *
     * @param  array<string, mixed>  $data  adset_id?, file_ids?, captions?, identity?
     */
    public function update(User $by, AdLaunch $l, array $data, int $revision): AdLaunch
    {
        $inReview = $l->state === LaunchState::BuyerReview;
        $editable = in_array($l->state, [LaunchState::Draft, LaunchState::ChangesRequested, LaunchState::BuyerReview], true);
        if (! $editable) {
            throw WriteDenied::make('launch_state', ['state' => $l->state->value]);
        }
        if ($inReview ? ! LaunchPolicy::isReviewer($by, $l) : ! LaunchPolicy::canEditDraft($by, $l)) {
            throw WriteDenied::make('launch_forbidden');
        }
        $m = $l->material()->with('files')->firstOrFail();
        $attrs = [];
        if (isset($data['adset_id']) && (int) $data['adset_id'] !== (int) $l->ad_set_id) {
            $slot = $this->openSlot((int) $data['adset_id']);
            if ($inReview && ! $by->hasAdsAuthority() && ! $this->policy->inScope($by, $slot->campaign->account)) {
                throw ValidationException::withMessages(['adset_id' => __('ads.launch.errors.slot_not_yours')]);
            }
            $attrs += $this->slotAttributes($slot);
        }
        if (isset($data['file_ids'])) {
            $attrs['file_ids'] = $this->materialFiles($m, $data['file_ids']);
        }
        if (isset($data['captions'])) {
            $attrs['captions'] = self::cleanCaptions($data['captions']);
        }
        if ($inReview && array_key_exists('identity', $data)) {
            $attrs['identity'] = $data['identity'];
        }
        $before = ['ad_set_id' => $l->ad_set_id, 'file_ids' => $l->file_ids, 'captions' => $l->captions];
        $after = array_merge($before, array_intersect_key($attrs, $before));

        return $this->transition($l, [$l->state], $l->state, $attrs + ['revision' => $revision + 1, 'checks' => null, 'checks_hash' => null],
            $revision, $by, ['before' => $before, 'after' => $after, 'by_reviewer' => $inReview], 'launch.edited');
    }

    /** T2: content sends the draft to the buyer holding the account today. Blocking checks must pass. */
    public function submit(User $by, AdLaunch $l, int $revision): AdLaunch
    {
        if (! in_array($l->state, [LaunchState::Draft, LaunchState::ChangesRequested], true)) {
            throw WriteDenied::make('launch_state', ['state' => $l->state->value]);
        }
        if (! LaunchPolicy::canEditDraft($by, $l)) {
            throw WriteDenied::make('launch_forbidden');
        }
        if ($l->revision !== $revision) {
            throw WriteDenied::make('launch_changed', ['revision' => $l->revision]);
        }
        $this->assertChecks($l, 'submit', $by);
        $buyer = AccountBuyer::today($l->account);
        $l = $this->transition($l, [LaunchState::Draft, LaunchState::ChangesRequested], LaunchState::BuyerReview, [
            'reviewer_buyer_id' => $buyer->id, 'submitted_at' => now(), 'revision' => $revision + 1,
            'original' => $l->original ?? ['ad_set_id' => $l->ad_set_id, 'file_ids' => $l->file_ids, 'captions' => $l->captions],
            'decision_code' => null, 'decision_reason' => null,
        ], $revision, $by);
        $this->notify->submitted($l);

        return $l;
    }

    /** T3: the reviewing buyer sends the draft back to content with a reason. */
    public function sendBack(User $by, AdLaunch $l, string $code, ?string $text): AdLaunch
    {
        if ($l->state !== LaunchState::BuyerReview) {
            throw WriteDenied::make('launch_state', ['state' => $l->state->value]);
        }
        if (! LaunchPolicy::isReviewer($by, $l)) {
            throw WriteDenied::make('launch_forbidden');
        }
        $this->assertReason($code, $text);
        $l = $this->transition($l, [LaunchState::BuyerReview], LaunchState::ChangesRequested, [
            'decided_by_id' => $by->id, 'decided_at' => now(), 'decision_code' => $code, 'decision_reason' => $text,
        ], null, $by, ['code' => $code, 'reason' => $text]);
        $this->notify->changesRequested($l);

        return $l;
    }

    /** T16: content withdraws its draft; the buyer withdraws a draft under review or one whose creation failed. */
    public function withdraw(User $by, AdLaunch $l): AdLaunch
    {
        $from = $l->state;
        $allowed = match ($from) {
            LaunchState::Draft, LaunchState::ChangesRequested => LaunchPolicy::canEditDraft($by, $l),
            LaunchState::BuyerReview, LaunchState::CreateFailed => LaunchPolicy::isReviewer($by, $l),
            default => null,
        };
        if ($allowed === null) {
            throw WriteDenied::make('launch_state', ['state' => $from->value]);
        }
        if (! $allowed) {
            throw WriteDenied::make('launch_forbidden');
        }
        $l = $this->transition($l, [$from], LaunchState::Withdrawn, ['decided_by_id' => $by->id, 'decided_at' => now()], null, $by);
        if ($from === LaunchState::CreateFailed) {
            $this->archive($l);
        }

        return $l;
    }

    /** T5: the reviewing buyer forwards; the PAUSED ads are created through the existing publish path (D2, O5). */
    public function forward(User $by, AdLaunch $l, int $revision): AdLaunch
    {
        if ($l->state !== LaunchState::BuyerReview) {
            throw WriteDenied::make('launch_state', ['state' => $l->state->value]);
        }
        if (! LaunchPolicy::isReviewer($by, $l)) {
            throw WriteDenied::make('launch_forbidden');
        }
        if ($l->revision !== $revision) {
            throw WriteDenied::make('launch_changed', ['revision' => $l->revision]);
        }
        if (! WriteSwitch::allows('publish')) {
            throw WriteDenied::make(WriteSwitch::CODE);
        }
        $this->assertChecks($l, 'forward', $by);
        $identity = $this->identityFor($l);
        $l = $this->transition($l, [LaunchState::BuyerReview], LaunchState::CreatingPaused, [
            'forwarded_by_id' => $by->id, 'forwarded_at' => now(), 'identity' => $identity, 'revision' => $revision + 1, 'last_error' => null,
        ], $revision, $by);

        try {
            $this->publish->publish($by, $l->material()->with('product')->firstOrFail(), $l->account, [
                'campaign_id' => (string) $l->campaign_external_id, 'campaign_name' => $l->campaign_name,
                'adset_id' => (string) $l->adset_external_id, 'adset_name' => $l->adset_name, 'identity' => $identity,
                'file_ids' => array_map('intval', (array) $l->file_ids), 'captions' => (array) $l->captions,
            ], 'launch-'.$l->public_id.'-r'.$l->revision, false, $l);
        } catch (DuplicatePublication|ValidationException $e) {
            $this->transition($l, [LaunchState::CreatingPaused], LaunchState::BuyerReview, ['last_error' => mb_substr($e->getMessage(), 0, 1000)],
                null, $by, ['error' => class_basename($e)], 'launch.forward_failed');

            throw $e;
        }
        $this->notify->forwarded($l);

        return $l->refresh(); // with a sync queue the ads may already be done
    }

    /** T6: creating_paused → awaiting_approval once every ad is created and synced; every ad finished with an error → create_failed. */
    public function syncCreating(AdLaunch $l): AdLaunch
    {
        $l->refresh();
        if ($l->state !== LaunchState::CreatingPaused) {
            return $l;
        }
        $rows = AdPublication::query()->where('ad_launch_id', $l->id)->whereNull('archived_at')->get();
        if ($rows->isEmpty()) {
            return $l;
        }
        if ($rows->every(fn (AdPublication $p) => $p->isFinished()) && $rows->contains(fn (AdPublication $p) => $p->status === AdPublication::ERROR)) {
            $error = (string) $rows->firstWhere('status', AdPublication::ERROR)?->error;
            $l = $this->transition($l, [LaunchState::CreatingPaused], LaunchState::CreateFailed, ['last_error' => mb_substr($error, 0, 1000)]);
            $this->notify->createFailed($l);

            return $l;
        }
        if ($rows->every(fn (AdPublication $p) => $p->status === AdPublication::DONE && $p->linked_at !== null)) {
            $results = $this->checks->run($l, 'approve');
            $l = $this->transition($l, [LaunchState::CreatingPaused], LaunchState::AwaitingApproval, [
                'awaiting_at' => now(), 'expires_at' => now()->addDays($this->settings->expiryDays()), 'expiring_notified_at' => null,
                'checks' => array_map(fn (CheckResult $c) => $c->toArray(), $results), 'checks_hash' => LaunchChecks::hash($l, $results),
            ]);
            $this->notify->awaitingApproval($l);
        }

        return $l;
    }

    /** The AdPublication saved hook: a launch ad changed state or got linked. Another worker winning the move is fine. */
    public function publicationChanged(AdPublication $p): void
    {
        $l = $p->launch;
        if ($l === null || $l->state !== LaunchState::CreatingPaused) {
            return;
        }
        try {
            $this->syncCreating($l);
        } catch (WriteDenied) {
            // another worker made the move
        }
    }

    /** E1: re-queue the failed ads whose ad-create request never left; an ad that may exist is never re-created (E2). */
    public function retry(User $by, AdLaunch $l): AdLaunch
    {
        if ($l->state !== LaunchState::CreateFailed) {
            throw WriteDenied::make('launch_state', ['state' => $l->state->value]);
        }
        if (! LaunchPolicy::isReviewer($by, $l)) {
            throw WriteDenied::make('launch_forbidden');
        }
        if (! WriteSwitch::allows('publish')) {
            throw WriteDenied::make(WriteSwitch::CODE);
        }
        $rows = AdPublication::query()->with('account')->where('ad_launch_id', $l->id)->whereNull('archived_at')
            ->where('status', AdPublication::ERROR)->whereNull('ad_requested_at')->get();
        if ($rows->isEmpty()) {
            throw WriteDenied::make('retry_not_possible');
        }
        $l = $this->transition($l, [LaunchState::CreateFailed], LaunchState::CreatingPaused, ['last_error' => null], null, $by);
        foreach ($rows as $p) {
            try {
                $p->forceFill(['status' => AdPublication::QUEUED, 'error' => null, 'attempts' => 0,
                    'open_key' => PublishService::openKey($p->account, (string) $p->adset_external_id, (int) $p->ad_material_file_id,
                        ['headline' => $p->headline, 'primary_text' => $p->primary_text, 'cta' => $p->cta])])->save();
            } catch (UniqueConstraintViolationException) {
                continue; // the same ad is in flight elsewhere: this row stays in error
            }
            PublishAd::dispatch($p->id);
        }

        return $this->syncCreating($l);
    }

    /**
     * A10: the launch's identity, else the one remembered for the account, else the first the platform lists.
     *
     * @return array{page_id: string, page_name: string, instagram_id: ?string}
     *
     * @throws WriteDenied 422 identity_missing
     */
    private function identityFor(AdLaunch $l): array
    {
        $saved = $l->identity ?: $this->adsSettings->get(PublishService::identityKey($l->account));
        if (is_array($saved) && (string) ($saved['page_id'] ?? '') !== '') {
            return ['page_id' => (string) $saved['page_id'], 'page_name' => (string) ($saved['page_name'] ?? ''), 'instagram_id' => ($saved['instagram_id'] ?? null) ?: null];
        }
        try {
            $writer = $this->drivers->writer(AdPlatform::from($l->account->platform));
            WriteGuard::check($l->account, $writer);
            $first = $writer->identities($l->account)[0] ?? null;
        } catch (WriteRefused|AdsApiException) {
            $first = null;
        }
        if ($first === null) {
            throw WriteDenied::make('identity_missing');
        }

        return ['page_id' => $first->pageId, 'page_name' => $first->pageName, 'instagram_id' => $first->instagramId];
    }

    /** T9: the manager returns the card to the buyer with a reason; the paused ads are archived (O5) and the deadline cleared. */
    public function managerReturn(User $by, AdLaunch $l, string $code, ?string $text): AdLaunch
    {
        return $this->decide($by, $l, LaunchState::BuyerReview, $code, $text, 'launch.returned', fn (AdLaunch $l) => $this->notify->returned($l));
    }

    /** T10: the manager rejects; terminal; the paused ads are archived. */
    public function reject(User $by, AdLaunch $l, string $code, ?string $text): AdLaunch
    {
        return $this->decide($by, $l, LaunchState::Rejected, $code, $text, 'launch.rejected', fn (AdLaunch $l) => $this->notify->rejected($l));
    }

    private function decide(User $by, AdLaunch $l, LaunchState $to, string $code, ?string $text, string $event, callable $notify): AdLaunch
    {
        if (! LaunchPolicy::canApprove($by)) {
            throw WriteDenied::make('ads_authority_required');
        }
        $this->assertReason($code, $text);
        $l = $this->transition($l, [LaunchState::AwaitingApproval], $to, [
            'decided_by_id' => $by->id, 'decided_at' => now(), 'decision_code' => $code, 'decision_reason' => $text,
            'awaiting_at' => null, 'expires_at' => null, 'expiring_notified_at' => null, 'checks' => null, 'checks_hash' => null,
        ], null, $by, ['code' => $code, 'reason' => $text], $event);
        $this->archive($l);
        $notify($l);

        return $l;
    }

    /** E13: a closed slot sends its launches under review back to content (system reason slot_closed). */
    public function slotClosed(AdSet $s, ?User $by): int
    {
        $n = 0;
        foreach (AdLaunch::query()->where('ad_set_id', $s->id)->where('state', LaunchState::BuyerReview->value)->get() as $l) {
            try {
                $l = $this->transition($l, [LaunchState::BuyerReview], LaunchState::ChangesRequested, [
                    'decided_by_id' => $by?->id, 'decided_at' => now(), 'decision_code' => 'slot_closed', 'decision_reason' => null,
                ], null, $by, ['code' => 'slot_closed']);
                $this->notify->changesRequested($l);
                $n++;
            } catch (WriteDenied) {
                // moved on meanwhile
            }
        }

        return $n;
    }

    /**
     * The one compare-and-set every move goes through: from one of $from (and $revision when given) to $to.
     *
     * @param  list<LaunchState>  $from
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $meta
     *
     * @throws WriteDenied 409 launch_state (state moved on) / launch_changed (revision moved on)
     */
    public function transition(AdLaunch $l, array $from, LaunchState $to, array $attrs = [], ?int $revision = null, ?User $actor = null, array $meta = [], ?string $event = null): AdLaunch
    {
        foreach ($from as $f) {
            if ($f !== $to && ! $f->canMoveTo($to)) {
                throw new LogicException("launch {$f->value} -> {$to->value} is not a transition");
            }
        }
        $values = ['state' => $to->value, 'updated_at' => now()];
        foreach ($attrs as $k => $v) {
            $values[$k] = match (true) {
                $v instanceof LaunchState => $v->value,
                is_array($v) => json_encode($v, JSON_UNESCAPED_UNICODE),
                default => $v,
            };
        }
        $q = AdLaunch::query()->whereKey($l->id)->whereIn('state', array_map(fn (LaunchState $s) => $s->value, $from));
        if ($revision !== null) {
            $q->where('revision', $revision);
        }
        if ($q->update($values) !== 1) {
            $fresh = $l->fresh(['decider']);
            if ($fresh !== null && $revision !== null && in_array($fresh->state, $from, true) && $fresh->revision !== $revision) {
                throw WriteDenied::make('launch_changed', ['revision' => $fresh->revision]);
            }

            throw WriteDenied::make('launch_state', array_filter(['state' => $fresh?->state->value, 'by' => $fresh?->decider?->name]));
        }
        $old = $l->state;
        $l->refresh();
        AdsAudit::record($event ?? 'launch.'.$to->value, $l, ['state' => $old->value], ['state' => $to->value],
            array_merge(['public_id' => $l->public_id, 'revision' => $l->revision], $meta), $actor);
        event(new LaunchMoved($l, $old, $to));

        return $l;
    }

    /** O5: the launch's ads leave the workflow locally (open_key released); the paused ads stay PAUSED, G1 keeps them off. */
    public function archive(AdLaunch $l): int
    {
        return AdPublication::query()->where('ad_launch_id', $l->id)->whereNull('archived_at')
            ->update(['archived_at' => now(), 'open_key' => null, 'updated_at' => now()]);
    }

    /**
     * @param  list<array<string, mixed>>  $captions
     * @return list<array{headline: string, primary_text: string, cta: string}>
     *
     * @throws ValidationException two identical captions (they would collide on the publish open_key)
     */
    public static function cleanCaptions(array $captions): array
    {
        $out = array_values(array_map(fn (array $c) => [
            'headline' => trim((string) $c['headline']), 'primary_text' => trim((string) $c['primary_text']), 'cta' => (string) $c['cta'],
        ], $captions));
        $signatures = array_map(fn (array $c) => $c['headline']."\n".$c['primary_text']."\n".$c['cta'], $out);
        if (count(array_unique($signatures)) < count($signatures)) {
            throw ValidationException::withMessages(['captions' => __('ads.publish.duplicate_captions')]);
        }

        return $out;
    }

    /** @throws WriteDenied 422 checks_failed (details.keys, details.checks) */
    private function assertChecks(AdLaunch $l, string $phase, User $by): void
    {
        $results = $this->checks->run($l, $phase, $by);
        $this->checks->store($l, $results);
        $blocking = LaunchChecks::blocking($results);
        if ($blocking !== []) {
            throw WriteDenied::make('checks_failed', [
                'keys' => array_map(fn (CheckResult $c) => $c->key, $blocking),
                'checks' => array_map(fn (CheckResult $c) => $c->toArray(), $results),
            ]);
        }
    }

    private function assertReason(string $code, ?string $text): void
    {
        if (! in_array($code, self::REASONS, true) || ($code === 'other' && trim((string) $text) === '')) {
            throw ValidationException::withMessages(['code' => __('ads.launch.errors.reason_required')]);
        }
    }

    /** @throws ValidationException */
    private function openSlot(int $adSetId): AdSet
    {
        $slot = AdSet::query()->with('campaign.account')->find($adSetId);
        $account = $slot?->campaign?->account;
        if ($slot === null || $slot->open_for_drafts_at === null || $account === null || ! $account->is_active || AccountBuyer::today($account) === null) {
            throw ValidationException::withMessages(['adset_id' => __('ads.launch.errors.slot_closed')]);
        }

        return $slot;
    }

    /** @return array<string, mixed> */
    private function slotAttributes(AdSet $s): array
    {
        return [
            'ad_account_id' => $s->campaign->ad_account_id, 'ad_set_id' => $s->id,
            'campaign_external_id' => $s->campaign->external_id, 'campaign_name' => $s->campaign->name,
            'adset_external_id' => $s->external_id, 'adset_name' => $s->name,
        ];
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function materialFiles(AdMaterial $m, array $ids): array
    {
        $own = $m->files->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || array_diff($ids, $own) !== []) {
            throw ValidationException::withMessages(['file_ids' => __('ads.publish.bad_file')]);
        }

        return $ids;
    }
}
