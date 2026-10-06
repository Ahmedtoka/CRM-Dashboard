<?php

namespace App\Ads\Materials;

use App\Inbox\UserNotifier;
use App\Models\AdMaterial;
use App\Models\User;

/**
 * Flags running (activated) materials whose product ran out of stock and tells the buyer + supervisors once per
 * out-of-stock episode. The stock rule is MaterialService::stockSql() — never restated here.
 */
final class StockWatcher
{
    public const NOTIFICATION_TYPE = 'ads.need_stop';

    public const LINK = '/ads/materials?status=live&stock=out';

    public function __construct(private readonly UserNotifier $notifier) {}

    /**
     * @param  list<int>|null  $productIds  limit the check to these products (null = every activated material)
     * @return array{flagged: int, cleared: int}
     */
    public function run(?array $productIds = null): array
    {
        $flagged = 0;

        $base = fn () => AdMaterial::query()
            ->where('status', 'live')
            ->whereNotNull('product_id')
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds));

        $stock = '('.MaterialService::stockSql().')';

        // Stock back (or the product is gone): clear the flag, silently.
        $cleared = $base()->whereNotNull('need_stop_at')->whereRaw("$stock <> 'out'")->update(['need_stop_at' => null]);

        // New out-of-stock episode: claim the flag atomically, then notify only the claimer.
        $base()->whereNull('need_stop_at')->whereRaw("$stock = 'out'")->with(['product', 'buyer.user'])->get()
            ->each(function (AdMaterial $m) use (&$flagged) {
                $claimed = AdMaterial::query()->whereKey($m->id)->whereNull('need_stop_at')->update(['need_stop_at' => now()]);

                if ($claimed === 1) {
                    $flagged++;
                    $this->notify($m);
                }
            });

        return ['flagged' => $flagged, 'cleared' => $cleared];
    }

    private function notify(AdMaterial $m): void
    {
        $data = [
            'material_id' => $m->id,
            'title' => $m->title,
            'name' => $m->title,
            'product_title' => $m->product?->title,
            'link' => self::LINK,
        ];

        $buyerUser = $m->buyer?->user;
        if ($buyerUser instanceof User && $buyerUser->is_active) {
            $this->notifier->notify($buyerUser, self::NOTIFICATION_TYPE, $data);
        }

        User::query()->where('is_active', true)->get()
            ->filter(fn (User $u) => $u->isSupervisorOrAbove() && $u->id !== $buyerUser?->id)
            ->each(fn (User $u) => $this->notifier->notify($u, self::NOTIFICATION_TYPE, $data));
    }
}
