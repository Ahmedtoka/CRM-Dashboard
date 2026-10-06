<?php

namespace App\Ads\Materials;

use App\Ads\Alerts\RuleSettings;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Launch\LaunchService;
use App\Ads\Launch\LaunchState;
use App\Inbox\UserNotifier;
use App\Models\AdLaunch;
use App\Models\AdMaterial;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

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
