<?php

namespace App\Ads\Materials;

use App\Ads\Alerts\RuleSettings;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Launch\LaunchService;
use App\Ads\Launch\LaunchState;
use App\Ads\Reports\AdsFilter;
use App\Inbox\UserNotifier;
use App\Models\AdLaunch;
use App\Models\AdMaterial;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stock watch (fixes C3, D6): every material with a non-terminal launch or a running linked ad is watched. A running one
 * that ran out is flagged once per episode and its buyer(s) + supervisors get ads.need_stop with a one-click Stop link
 * (never an automatic Stop); pre-live launches go on_hold and come back on restock. The stock rule is
 * MaterialService::stockSql() — never restated here.
 */
final class StockWatcher
{
    public const NOTIFICATION_TYPE = 'ads.need_stop';

    public const LINK = '/ads/materials?status=live&stock=out';

    /** Round 3: «خطر مخزون» — a running material whose product is at or under the low-stock number (RuleSettings::lowStockUnits). */
    public const LOW_NOTIFICATION_TYPE = 'ads.stock_low';

    public const LOW_LINK = '/ads/decisions';

    public function __construct(private readonly UserNotifier $notifier, private readonly LaunchService $launches) {}

    /** @param  Builder<AdMaterial>  $q  @return Builder<AdMaterial> */
    public static function watched(Builder $q): Builder
    {
        return $q->where(fn (Builder $w) => self::running($w)->orWhereExists(fn ($e) => $e->selectRaw('1')->from('ad_launches')
            ->whereColumn('ad_launches.ad_material_id', 'ad_materials.id')->whereIn('ad_launches.state', LaunchState::NON_TERMINAL_VALUES)));
    }

    /** @param  Builder<AdMaterial>  $q  @return Builder<AdMaterial> */
    public static function running(Builder $q): Builder
    {
        return $q->where(fn (Builder $w) => $w->whereHas('ads', fn ($a) => $a->whereRaw("UPPER(ads.status) = 'ACTIVE'"))
            ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('ad_launches')
                ->whereColumn('ad_launches.ad_material_id', 'ad_materials.id')->whereIn('ad_launches.state', ['live', 'launching'])));
    }

    /**
     * @param  list<int>|null  $productIds  limit the check to these products (null = every watched material)
     * @return array{flagged: int, cleared: int}
     */
    public function run(?array $productIds = null): array
    {
        $flagged = 0;
        $base = fn () => AdMaterial::query()->whereNotNull('product_id')
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds));
        $stock = '('.MaterialService::stockSql().')';

        // Stock back, the product is gone, or nothing runs any more: clear the flag, silently.
        $cleared = $base()->whereNotNull('need_stop_at')
            ->where(fn ($q) => $q->whereRaw("$stock <> 'out'")->orWhereNot(fn ($n) => self::running($n)))
            ->update(['need_stop_at' => null]);

        // New out-of-stock episode on something running: claim the flag atomically, then notify only the claimer.
        self::running($base()->whereNull('need_stop_at')->whereRaw("$stock = 'out'"))->with(['product', 'buyer.user'])->get()
            ->each(function (AdMaterial $m) use (&$flagged) {
                if (AdMaterial::query()->whereKey($m->id)->whereNull('need_stop_at')->update(['need_stop_at' => now()]) === 1) {
                    $flagged++;
                    $this->notify($m);
                }
            });

        return ['flagged' => $flagged, 'cleared' => $cleared];
    }

    /**
     * Round 3: the low-stock episode. A running material whose product still has stock but at most `threshold` units is
     * flagged once (low_stock_at) and its buyer + the supervisors get ads.stock_low with the running ads' names; the flag
     * clears silently when stock passes the threshold again, runs out (that is need_stop's episode) or nothing runs.
     *
     * @param  list<int>|null  $productIds
     * @return array{flagged: int, cleared: int}
     */
    public function low(?array $productIds = null): array
    {
        $threshold = $this->lowThreshold();
        $base = fn () => AdMaterial::query()->whereNotNull('product_id')
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds));
        $stock = '('.MaterialService::stockSql().')';
        $units = MaterialService::inventorySql();

        $cleared = $base()->whereNotNull('low_stock_at')
            ->where(fn ($q) => $q->whereRaw("$stock <> 'in'")->orWhereRaw("$units > ?", [$threshold])->orWhereNot(fn ($n) => self::running($n)))
            ->update(['low_stock_at' => null]);

        $flagged = 0;
        self::running($base()->whereNull('low_stock_at')->whereRaw("$stock = 'in'")->whereRaw("$units <= ?", [$threshold]))
            ->with(['product', 'buyer.user', 'ads:id,name,status'])->get()
            ->each(function (AdMaterial $m) use (&$flagged, $threshold) {
                if (AdMaterial::query()->whereKey($m->id)->whereNull('low_stock_at')->update(['low_stock_at' => now()]) === 1) {
                    $flagged++;
                    $this->notifyLow($m, $threshold);
                }
            });

        return ['flagged' => $flagged, 'cleared' => $cleared];
    }

    /** One low-stock number for launch checks, restock alerts and the stock-risk decision (RuleSettings::lowStockUnits). */
    public function lowThreshold(): int
    {
        return app(RuleSettings::class)->lowStockUnits();
    }

    /**
     * The running ads at stock risk for «قرار اليوم»: every active ad linked to a flagged material (out: need_stop_at;
     * low: low_stock_at), in the filter's accounts, with the product and the units left.
     *
     * @return list<array{ad_id:int, external_id:string, name:string, status:?string, thumbnail_url:?string, account_id:int, account:string, platform:string, material_id:int, material:string, product:?string, stock:'out'|'low', inventory:int, threshold:int}>
     */
    public function riskRows(?AdsFilter $f = null): array
    {
        $threshold = $this->lowThreshold();
        $q = DB::table('ad_material_ads as l')
            ->join('ad_materials as mat', 'mat.id', '=', 'l.ad_material_id')
            ->join('ads as ad', 'ad.id', '=', 'l.ad_id')
            ->join('ad_accounts as acc', 'acc.id', '=', 'ad.ad_account_id')
            ->leftJoin('products as p', 'p.id', '=', 'mat.product_id')
            ->where(fn ($w) => $w->whereNotNull('mat.need_stop_at')->orWhereNotNull('mat.low_stock_at'))
            ->whereRaw("UPPER(ad.status) = 'ACTIVE'")
            ->select(['l.ad_id', 'ad.external_id', 'ad.name', 'ad.status', 'ad.thumbnail_url', 'acc.id as account_id', 'acc.name as account', 'acc.platform',
                'mat.id as material_id', 'mat.title as material', 'p.title as product', 'mat.need_stop_at', 'mat.low_stock_at'])
            ->selectRaw(str_replace('ad_materials.', 'mat.', MaterialService::inventorySql()).' as inventory')
            ->orderBy('mat.id')->orderBy('l.ad_id');
        if ($f?->accountIds !== null) {
            $q->whereIn('ad.ad_account_id', $f->accountIds);
        }
        if ($f?->platform !== null) {
            $q->where('acc.platform', $f->platform);
        }

        $out = [];
        foreach ($q->get() as $r) {
            $id = (int) $r->ad_id;
            if (isset($out[$id]) && $out[$id]['stock'] === 'out') {
                continue; // one row per ad; out beats low
            }
            $out[$id] = [
                'ad_id' => $id, 'external_id' => (string) $r->external_id, 'name' => (string) $r->name, 'status' => $r->status, 'thumbnail_url' => $r->thumbnail_url,
                'account_id' => (int) $r->account_id, 'account' => (string) $r->account, 'platform' => (string) $r->platform,
                'material_id' => (int) $r->material_id, 'material' => (string) $r->material, 'product' => $r->product,
                'stock' => $r->need_stop_at !== null ? 'out' : 'low', 'inventory' => (int) $r->inventory, 'threshold' => $threshold,
            ];
        }

        return array_values($out);
    }

    private function notifyLow(AdMaterial $m, int $threshold): void
    {
        $data = [
            'material_id' => $m->id,
            'title' => $m->title,
            'product_title' => $m->product?->title,
            'inventory' => (int) ($m->product?->variants()->sum('inventory_quantity') ?? 0),
            'threshold' => $threshold,
            'ads' => $m->ads->filter(fn ($a) => strtoupper((string) $a->status) === 'ACTIVE')->pluck('name')->values()->all(),
            'link' => self::LOW_LINK,
        ];
        $recipients = collect([$m->buyer?->user])->filter(fn ($u) => $u instanceof User && $u->is_active);
        User::query()->where('is_active', true)->get()->filter(fn (User $u) => $u->isSupervisorOrAbove())
            ->each(fn (User $u) => $recipients->push($u));
        $recipients->unique('id')->each(fn (User $u) => $this->notifier->notify($u, self::LOW_NOTIFICATION_TYPE, $data));
    }

    /**
     * T11: pre-live launches of out-of-stock materials go on hold; held launches whose stock is back are released.
     *
     * @param  list<int>|null  $productIds
     * @return array{held: int, released: int}
     */
    public function holds(?array $productIds = null): array
    {
        $stock = '('.MaterialService::stockSql().')';
        $materials = fn (string $is) => AdMaterial::query()->select('ad_materials.id')->whereNotNull('product_id')
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))->whereRaw("$stock = ?", [$is]);
        $held = 0;
        $released = 0;
        foreach (AdLaunch::query()->whereIn('state', LaunchState::HOLDABLE_VALUES)->whereIn('ad_material_id', $materials('out'))->get() as $l) {
            try {
                $this->launches->hold($l);
                $held++;
            } catch (WriteDenied) {
                // moved meanwhile
            }
        }
        foreach (AdLaunch::query()->where('state', LaunchState::OnHold->value)->whereIn('ad_material_id', $materials('in'))->get() as $l) {
            try {
                $this->launches->release($l);
                $released++;
            } catch (WriteDenied) {
                // moved meanwhile
            }
        }

        // The product is gone (deleted or unlinked): a held launch can never come back, so it ends (counted as released).
        $gone = AdLaunch::query()->where('state', LaunchState::OnHold->value)->where(fn ($q) => $q->whereNull('ad_material_id')
            ->orWhereIn('ad_material_id', AdMaterial::query()->select('ad_materials.id')
                // a scoped run (one product's Shopify update) also sees materials unlinked from any product
                ->when($productIds !== null, fn ($m) => $m->where(fn ($w) => $w->whereIn('product_id', $productIds)->orWhereNull('product_id')))
                ->whereRaw("$stock = 'none'")));
        foreach ($gone->get() as $l) {
            try {
                $this->launches->expire($l, 'product_gone');
                $released++;
            } catch (WriteDenied) {
                // moved meanwhile
            }
        }

        return ['held' => $held, 'released' => $released];
    }

    private function notify(AdMaterial $m): void
    {
        // R-06: once decision notifications are on, the all.out_of_stock alert (grouped bell item) replaces this notice.
        if (app(RuleSettings::class)->notifyEnabled()) {
            return;
        }
        $live = AdLaunch::query()->with('reviewer.user')->where('ad_material_id', $m->id)->whereIn('state', ['live', 'launching'])->get();
        $data = [
            'material_id' => $m->id,
            'title' => $m->title,
            'name' => $m->title,
            'product_title' => $m->product?->title,
            'launch_ids' => $live->pluck('public_id')->values()->all(),
            'link' => $live->isEmpty() ? self::LINK : '/ads/launches?box=live&material='.$m->id.'&stop=1',
        ];

        $recipients = collect([$m->buyer?->user, ...$live->map(fn (AdLaunch $l) => $l->reviewer?->user)->all()])
            ->filter(fn ($u) => $u instanceof User && $u->is_active);
        User::query()->where('is_active', true)->get()->filter(fn (User $u) => $u->isSupervisorOrAbove())
            ->each(fn (User $u) => $recipients->push($u));
        $recipients->unique('id')->each(fn (User $u) => $this->notifier->notify($u, self::NOTIFICATION_TYPE, $data));
    }
}
