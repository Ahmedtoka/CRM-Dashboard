<?php

namespace App\Ads\Launch;

use App\Ads\Control\PublishService;
use App\Ads\Control\WritableAccounts;
use App\Ads\Control\Write\RunGuard;
use App\Ads\Control\Write\WriteActionService;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Control\Write\WriteSwitch;
use App\Ads\Materials\MaterialService;
use App\Ads\Naming;
use App\Ads\Platforms\AdPlatform;
use App\Models\Ad;
use App\Models\AdCampaign;
use App\Models\AdLaunch;
use App\Models\AdMaterialFile;
use App\Models\AdPublication;
use App\Models\BotSetting;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The pre-approval checklist (spec 3.4, R section 3, L 3.2), built once and run at submit, forward and approve. run()
 * reads CRM rows only; live() reads the platform (approve only, A4). The hash binds a card to what the approver saw (G6).
 */
final class LaunchChecks
{
    /** Re-checked at approve, never part of the hash (A2). */
    public const VOLATILE = ['landing_http', 'meta_review_pending', 'ap.activations_left', 'ap.writes_on'];

    public const MAX_VIDEO_SECONDS = 240;

    public const PRIMARY_TEXT_MAX = 125;

    public const HEADLINE_MAX = 40;

    /** 9:16 Reels / Stories, 4:5 and 1:1 Feed. */
    private const RATIOS = [0.5625, 0.8, 1.0];

    public function __construct(
        private readonly PublishService $publish,
        private readonly LandingProbe $probe,
        private readonly LaunchSettings $settings,
        private readonly RunGuard $runGuard,
        private readonly WriteActionService $writes,
    ) {}

    /**
     * @param  'submit'|'forward'|'approve'  $phase
     * @return list<CheckResult>
     */
    public function run(AdLaunch $l, string $phase, ?User $actor = null): array
    {
        $l->loadMissing(['material.product.variants', 'material.files', 'account.connection', 'adSet.campaign']);
        $out = [];
        if ($phase !== 'approve') {
            $out[] = $this->slotOpen($l);
        }
        array_push($out, $this->buyerHoldsAccount($l), $this->accountWritable($l), $this->stock($l), $this->productActive($l),
            $this->landingHost($l), $this->utm($l), $this->captionPrice($l), $this->media($l), $this->duplicate($l));
        if ($phase === 'approve') {
            array_push($out, ...$this->pausedAds($l));
            array_push($out, $this->activationsLeft($l, $actor), $this->writesOn());
        }
        array_push($out, $this->aspectRatio($l), $this->captionLength($l), $this->ctaObjective($l), $this->sizesInStock($l),
            $this->liveOtherBuyer($l), $this->naming($l), $this->parentPaused($l), $this->landingHttp($l));

        return $out;
    }

    /**
     * Approve only: one live read per ad; the Run guard's budget-cap verdict (incl. the CBO parent) and the parents' live status.
     *
     * @return list<CheckResult>
     */
    public function live(AdLaunch $l, User $approver): array
    {
        $account = $l->account;
        if ($account === null) {
            return [CheckResult::block('ap.budget_cap', ['reason' => '-'])];
        }
        $refusal = null;
        $rows = [];
        $parentPaused = false;
        foreach ($l->publications()->whereNull('archived_at')->whereNotNull('external_ad_id')->get() as $p) {
            $ad = Ad::query()->where('ad_account_id', $account->id)->where('external_id', $p->external_ad_id)->first();
            if ($ad === null) {
                continue; // ap.paused_exists already blocks
            }
            try {
                $state = $this->runGuard->read($account, 'ad', (string) $p->external_ad_id);
            } catch (WriteDenied $e) {
                $refusal ??= $e;

                continue;
            }
            $verdict = $this->runGuard->budgetVerdict($approver, $account, $ad, $state);
            $refusal ??= $verdict['refusal'];
            $rows = $verdict['rows'];
            foreach ($state->parents as $parent) {
                if (strtoupper((string) $parent['status']) !== 'ACTIVE') {
                    $parentPaused = true;
                }
            }
        }

        return [
            $refusal === null ? CheckResult::pass('ap.budget_cap', [], ['rows' => $rows]) : CheckResult::block('ap.budget_cap', ['reason' => $refusal->getMessage()], ['code' => $refusal->errorCode]),
            $parentPaused ? CheckResult::warn('parent_paused') : CheckResult::pass('parent_paused'),
        ];
    }

    /**
     * @param  list<CheckResult>  $a
     * @param  list<CheckResult>  $b
     * @return list<CheckResult>
     */
    public static function merge(array $a, array $b): array
    {
        $out = [];
        foreach ([...$a, ...$b] as $r) {
            $out[$r->key] = $r;
        }

        return array_values($out);
    }

    /** @param  list<CheckResult>  $results */
    public static function hash(AdLaunch $l, array $results): string
    {
        $rows = [];
        foreach ($results as $r) {
            if (! in_array($r->key, self::VOLATILE, true)) {
                $rows[] = [$r->key, $r->level, $r->details];
            }
        }

        return hash('sha256', (string) json_encode(['revision' => (int) $l->revision, 'checks' => $rows], JSON_UNESCAPED_UNICODE));
    }

    /** @param  list<CheckResult>  $results  @return list<CheckResult> */
    public static function blocking(array $results): array
    {
        return array_values(array_filter($results, fn (CheckResult $r) => $r->level === CheckResult::BLOCK));
    }

    /** @param  list<CheckResult>  $results  @return list<CheckResult> */
    public static function warnings(array $results): array
    {
        return array_values(array_filter($results, fn (CheckResult $r) => $r->level === CheckResult::WARN));
    }

    /** Keeps what the people saw on the launch (checks + hash); never touches state or revision. @param list<CheckResult> $results */
    public function store(AdLaunch $l, array $results): AdLaunch
    {
        $l->forceFill(['checks' => array_map(fn (CheckResult $r) => $r->toArray(), $results), 'checks_hash' => self::hash($l, $results)])->save();

        return $l;
    }

    // ---- blocking ------------------------------------------------------------------------------------------------

    private function slotOpen(AdLaunch $l): CheckResult
    {
        return $l->adSet?->open_for_drafts_at !== null ? CheckResult::pass('slot_open') : CheckResult::block('slot_open');
    }

    private function buyerHoldsAccount(AdLaunch $l): CheckResult
    {
        $buyer = $l->account !== null ? AccountBuyer::today($l->account) : null;

        return $buyer === null ? CheckResult::block('buyer_holds_account') : CheckResult::pass('buyer_holds_account', ['name' => $buyer->name], ['buyer_id' => $buyer->id]);
    }

    private function accountWritable(AdLaunch $l): CheckResult
    {
        $a = $l->account;
        $c = $a?->connection;
        $ok = $a !== null && $a->is_active && in_array($a->platform, [AdPlatform::Meta->value, AdPlatform::Tiktok->value], true)
            && WritableAccounts::allows($a) && ! in_array($c?->status, ['disabled', 'needs_reconnect'], true) && ! $c?->read_only;

        return $ok ? CheckResult::pass('account_writable', ['name' => $a->name]) : CheckResult::block('account_writable', ['name' => (string) $a?->name]);
    }

    private function stock(AdLaunch $l): CheckResult
    {
        $m = $l->material;
        $units = $this->units($l);
        if ($m?->product !== null) {
            $m->product->setAttribute('inventory', $units);
        }
        $stock = $m === null ? 'none' : MaterialService::stockOf($m);

        return $stock === 'in'
            ? CheckResult::pass('stock', ['units' => $units], ['bucket' => $units <= $this->settings->lowStockUnits() && $m?->stock_override === null ? 'low' : 'ok'])
            : CheckResult::block('stock', ['units' => $units], ['bucket' => 'out']);
    }

    private function productActive(AdLaunch $l): CheckResult
    {
        $p = $l->material?->product;

        return $p !== null && $p->status === 'active' ? CheckResult::pass('product_active') : CheckResult::block('product_active');
    }

    private function landingHost(AdLaunch $l): CheckResult
    {
        $link = (string) $l->link;
        $store = parse_url(BotSetting::current()->storeUrl(), PHP_URL_HOST);
        $ok = $link !== '' && parse_url($link, PHP_URL_SCHEME) === 'https' && is_string($store)
            && strcasecmp((string) parse_url($link, PHP_URL_HOST), $store) === 0;

        return $ok ? CheckResult::pass('landing_host', ['host' => (string) $store]) : CheckResult::block('landing_host', ['host' => (string) $store]);
    }

    private function utm(AdLaunch $l): CheckResult
    {
        $tags = $l->account !== null ? $this->publish->urlTags(AdPlatform::from($l->account->platform)) : '';
        $ok = str_contains($tags, 'utm_content=') && ! str_contains((string) $l->link, 'utm_');

        return $ok ? CheckResult::pass('utm') : CheckResult::block('utm');
    }

    private function captionPrice(AdLaunch $l): CheckResult
    {
        /** @var Collection<int, ProductVariant> $variants */
        $variants = $l->material?->product?->variants ?? collect();
        $prices = $variants->map(fn (ProductVariant $v) => (int) round((float) $v->price))->unique()->sort()->values()->all();
        $allowed = array_values(array_unique(array_merge($prices, $variants->pluck('compare_at_price')->filter()->map(fn ($p) => (int) round((float) $p))->all())));
        $bad = [];
        foreach (array_values((array) $l->captions) as $i => $c) {
            $wrong = array_values(array_diff(CaptionPriceParser::prices(($c['headline'] ?? '')."\n".($c['primary_text'] ?? '')), $allowed));
            if ($wrong !== []) {
                $bad[] = ['caption' => $i + 1, 'found' => $wrong];
            }
        }
        $shown = implode(' / ', $prices);

        return $bad === []
            ? CheckResult::pass('caption_price', ['price' => $shown], ['prices' => $prices])
            : CheckResult::block('caption_price', ['found' => implode(', ', array_merge(...array_column($bad, 'found'))), 'price' => $shown], ['prices' => $prices, 'bad' => $bad]);
    }

    private function media(AdLaunch $l): CheckResult
    {
        $files = $this->files($l);
        $max = (array) config('crm.ads.material_max_mb', ['video' => 500, 'image' => 20]);
        $problems = [];
        if ((array) $l->file_ids === []) {
            $problems[] = ['file_id' => null, 'reason' => 'none'];
        }
        foreach ((array) $l->file_ids as $id) {
            $f = $files->get($id);
            if ($f === null) {
                $problems[] = ['file_id' => $id, 'reason' => 'missing'];

                continue;
            }
            $video = str_starts_with((string) $f->mime, 'video/');
            $limit = (int) ($video ? ($max['video'] ?? 500) : ($max['image'] ?? 20)) * 1024 * 1024;
            if ((int) $f->size <= 0 || (int) $f->size > $limit) {
                $problems[] = ['file_id' => $id, 'reason' => 'size'];
            }
            if ($video && $f->thumb_path === null) {
                $problems[] = ['file_id' => $id, 'reason' => 'thumbnail'];
            }
            if ($video && (float) $f->duration > self::MAX_VIDEO_SECONDS) {
                $problems[] = ['file_id' => $id, 'reason' => 'duration'];
            }
        }

        return $problems === [] ? CheckResult::pass('media', ['n' => count((array) $l->file_ids)]) : CheckResult::block('media', ['n' => count($problems)], ['problems' => $problems]);
    }

    private function duplicate(AdLaunch $l): CheckResult
    {
        $keys = [];
        if ($l->account !== null && $l->adset_external_id !== null) {
            foreach ((array) $l->file_ids as $fileId) {
                foreach ((array) $l->captions as $c) {
                    $keys[] = PublishService::openKey($l->account, (string) $l->adset_external_id, (int) $fileId, $c);
                }
            }
        }
        $dup = $keys !== [] && AdPublication::query()->whereIn('open_key', $keys)
            ->where(fn ($q) => $q->whereNull('ad_launch_id')->orWhere('ad_launch_id', '<>', $l->id))->exists();

        return $dup ? CheckResult::block('duplicate') : CheckResult::pass('duplicate');
    }

    /** @return list<CheckResult> ap.paused_exists, ap.not_disapproved, meta_review_pending */
    private function pausedAds(AdLaunch $l): array
    {
        $pubs = $l->publications()->whereNull('archived_at')->get();
        $ads = Ad::query()->where('ad_account_id', $l->ad_account_id)->whereIn('external_id', $pubs->pluck('external_ad_id')->filter()->all())->get()->keyBy('external_id');
        $statuses = [];
        $missing = [];
        foreach ($pubs as $p) {
            $status = strtoupper((string) $ads->get((string) $p->external_ad_id)?->status);
            $statuses[$p->ad_name] = $status !== '' ? $status : 'MISSING';
            if ($p->status !== AdPublication::DONE || $status !== 'PAUSED') {
                $missing[] = $p->ad_name;
            }
        }
        $effective = fn (array $values) => $ads->filter(fn (Ad $a) => in_array(strtoupper((string) $a->effective_status), $values, true));
        $disapproved = $effective(['DISAPPROVED', 'WITH_ISSUES'])->pluck('name')->values()->all();
        $inReview = $effective(['PENDING_REVIEW', 'IN_PROCESS', 'PREAPPROVED'])->count();

        return [
            $pubs->isNotEmpty() && $missing === [] ? CheckResult::pass('ap.paused_exists', ['n' => $pubs->count()], ['statuses' => $statuses])
                : CheckResult::block('ap.paused_exists', ['names' => implode('، ', $missing)], ['statuses' => $statuses]),
            $disapproved === [] ? CheckResult::pass('ap.not_disapproved') : CheckResult::block('ap.not_disapproved', ['names' => implode('، ', $disapproved)], ['names' => $disapproved]),
            $inReview === 0 ? CheckResult::pass('meta_review_pending') : CheckResult::warn('meta_review_pending', ['n' => $inReview]),
        ];
    }

    private function activationsLeft(AdLaunch $l, ?User $actor): CheckResult
    {
        $need = $l->publications()->whereNull('archived_at')->count() ?: $l->adsCount();
        if ($actor === null || $l->account === null) {
            return CheckResult::pass('ap.activations_left', ['left' => '-', 'need' => $need]);
        }
        $left = $this->writes->activationsLeft($actor, $l->account);
        $n = min($left['user'], $left['account']);

        return $n >= $need ? CheckResult::pass('ap.activations_left', ['left' => $n, 'need' => $need], ['left' => $n])
            : CheckResult::block('ap.activations_left', ['left' => $n, 'need' => $need], ['left' => $n]);
    }

    private function writesOn(): CheckResult
    {
        return WriteSwitch::enabled() ? CheckResult::pass('ap.writes_on') : CheckResult::block('ap.writes_on');
    }

    // ---- warnings ------------------------------------------------------------------------------------------------

    private function aspectRatio(AdLaunch $l): CheckResult
    {
        $bad = $this->files($l)->only((array) $l->file_ids)->filter(function (AdMaterialFile $f) {
            if (! $f->width || ! $f->height) {
                return false;
            }
            $r = $f->width / $f->height;

            return $f->width < 1080 || collect(self::RATIOS)->every(fn (float $want) => abs($r - $want) > 0.03);
        })->count();

        return $bad === 0 ? CheckResult::pass('aspect_ratio') : CheckResult::warn('aspect_ratio', ['n' => $bad]);
    }

    private function captionLength(AdLaunch $l): CheckResult
    {
        $long = collect((array) $l->captions)->filter(fn (array $c) => mb_strlen((string) $c['primary_text']) > self::PRIMARY_TEXT_MAX
            || mb_strlen((string) $c['headline']) > self::HEADLINE_MAX)->count();

        return $long === 0 ? CheckResult::pass('caption_length') : CheckResult::warn('caption_length', ['n' => $long]);
    }

    private function ctaObjective(AdLaunch $l): CheckResult
    {
        $objective = strtoupper((string) AdCampaign::query()->where('ad_account_id', $l->ad_account_id)->where('external_id', $l->campaign_external_id)->value('objective'));
        $want = match (true) {
            str_contains($objective, 'MESSAGE') || str_contains($objective, 'ENGAGEMENT') => ['SEND_MESSAGE'],
            str_contains($objective, 'SALES') || str_contains($objective, 'CONVERSION') || str_contains($objective, 'TRAFFIC') => ['SHOP_NOW', 'ORDER_NOW', 'LEARN_MORE'],
            default => null,
        };
        if ($want === null) {
            return CheckResult::pass('cta_objective');
        }
        $bad = collect((array) $l->captions)->reject(fn (array $c) => in_array($c['cta'] ?? '', $want, true))->count();

        return $bad === 0 ? CheckResult::pass('cta_objective') : CheckResult::warn('cta_objective', ['n' => $bad, 'objective' => $objective]);
    }

    private function sizesInStock(AdLaunch $l): CheckResult
    {
        $variants = $l->material?->product?->variants ?? collect();
        if ($variants->isEmpty() || $l->material?->stock_override === true) {
            return CheckResult::pass('sizes_in_stock');
        }
        $inStock = $variants->filter(fn (ProductVariant $v) => (int) $v->inventory_quantity > 0)->count();
        $ok = $inStock / $variants->count() >= 0.5 && $this->units($l) > $this->settings->lowStockUnits();

        return $ok ? CheckResult::pass('sizes_in_stock') : CheckResult::warn('sizes_in_stock', ['in' => $inStock, 'all' => $variants->count(), 'units' => $this->units($l)]);
    }

    private function liveOtherBuyer(AdLaunch $l): CheckResult
    {
        $names = AdLaunch::query()->with('reviewer:id,name')->where('ad_material_id', $l->ad_material_id)->where('id', '<>', $l->id)
            ->where('state', LaunchState::Live->value)->whereNotNull('reviewer_buyer_id')
            ->when($l->reviewer_buyer_id !== null, fn ($q) => $q->where('reviewer_buyer_id', '<>', $l->reviewer_buyer_id))
            ->get()->pluck('reviewer.name')->filter()->unique()->values()->all();

        return $names === [] ? CheckResult::pass('live_other_buyer') : CheckResult::warn('live_other_buyer', ['names' => implode('، ', $names)]);
    }

    private function naming(AdLaunch $l): CheckResult
    {
        // The current CRM names (a rename after the draft counts), the launch snapshot when the row is gone.
        $campaign = AdCampaign::query()->where('ad_account_id', $l->ad_account_id)->where('external_id', $l->campaign_external_id)->value('name') ?? $l->campaign_name;
        $ok = Naming::checkCampaign((string) $campaign) && Naming::checkAdSet((string) ($l->adSet?->name ?? $l->adset_name));

        return $ok ? CheckResult::pass('naming') : CheckResult::warn('naming');
    }

    /** D4: the parent's own status from the CRM rows (the live one replaces it at approve). */
    private function parentPaused(AdLaunch $l): CheckResult
    {
        $set = strtoupper((string) $l->adSet?->status);
        $campaign = strtoupper((string) AdCampaign::query()->where('ad_account_id', $l->ad_account_id)->where('external_id', $l->campaign_external_id)->value('status'));
        $paused = ($set !== '' && $set !== 'ACTIVE') || ($campaign !== '' && $campaign !== 'ACTIVE');

        return $paused ? CheckResult::warn('parent_paused') : CheckResult::pass('parent_paused');
    }

    private function landingHttp(AdLaunch $l): CheckResult
    {
        $status = $l->link ? $this->probe->status((string) $l->link) : null;

        return $status === 200 ? CheckResult::pass('landing_http') : CheckResult::warn('landing_http', ['status' => $status ?? '-']);
    }

    // ---- helpers -------------------------------------------------------------------------------------------------

    /** @return Collection<int, AdMaterialFile> keyed by id */
    private function files(AdLaunch $l): Collection
    {
        return ($l->material?->files ?? collect())->keyBy('id');
    }

    private function units(AdLaunch $l): int
    {
        return (int) ($l->material?->product?->variants->sum('inventory_quantity') ?? 0);
    }
}
