<?php

namespace Tests\Support;

use App\Ads\Alerts\ChatSignals;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\AdMaterial;
use App\Models\AdSet;
use App\Models\AdsSyncRun;
use App\Models\Conversation;
use App\Models\ConversationAdReferral;
use App\Models\MediaBuyer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;

/** Builders for the S5 decision-feed tests. Every date is a Cairo day; the clock is frozen at 2026-10-06 10:00 Cairo. */
final class AlertWorld
{
    public const NOW = '2026-10-06 10:00:00';

    public const TZ = 'Africa/Cairo';

    private const SIZES = ['S', 'M', 'L', 'XL', 'XXL', '3XL'];

    public static function freeze(string $cairo = self::NOW): CarbonImmutable
    {
        $now = CarbonImmutable::parse($cairo, self::TZ);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        return $now;
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay();
    }

    public static function day(int $offset): string
    {
        return self::today()->addDays($offset)->toDateString();
    }

    /** A Meta account with an ok sync 15 minutes ago (fresh data). */
    public static function account(array $attrs = []): AdAccount
    {
        $a = AdAccount::factory()->meta()->create($attrs);
        AdsSyncRun::factory()->create([
            'ad_account_id' => $a->id, 'status' => 'ok', 'started_at' => now()->subMinutes(20), 'finished_at' => now()->subMinutes(15),
        ]);

        return $a;
    }

    /** A media buyer user holding the account since 2026-01-01. */
    public static function buyer(AdAccount $a): User
    {
        $u = User::factory()->create(['role' => UserRole::MediaBuyer]);
        $b = MediaBuyer::factory()->create(['user_id' => $u->id, 'name' => $u->name]);
        AdAccountAssignment::factory()->create(['ad_account_id' => $a->id, 'media_buyer_id' => $b->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);

        return $u;
    }

    public static function authority(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'ads_authority' => true]);
    }

    public static function ad(AdAccount $a, string $objective = 'OUTCOME_SALES', array $attrs = [], ?AdSet $set = null): Ad
    {
        if ($set === null) {
            $campaign = AdCampaign::factory()->create(['ad_account_id' => $a->id, 'objective' => $objective]);
            $set = AdSet::factory()->create(['ad_campaign_id' => $campaign->id]);
        }

        return Ad::factory()->create(array_merge([
            'ad_account_id' => $a->id, 'ad_campaign_id' => $set->ad_campaign_id, 'ad_set_id' => $set->id,
            'status' => 'ACTIVE', 'effective_status' => 'ACTIVE',
        ], $attrs));
    }

    public static function spend(Ad $ad, string $date, float $spend, array $more = []): AdDailyMetric
    {
        return AdDailyMetric::factory()->create(array_merge([
            'ad_id' => $ad->id, 'ad_account_id' => $ad->ad_account_id, 'date' => $date, 'spend' => $spend,
            'purchases' => 0, 'purchase_value' => 0, 'impressions' => 1000, 'clicks' => 10, 'reach' => 800,
        ], $more));
    }

    /** The same spend on each of $days days, the most recent one $lastOffset days from today (-1 = yesterday). */
    public static function spendDays(Ad $ad, int $days, float $perDay, int $lastOffset = -1, array $more = []): void
    {
        for ($i = 0; $i < $days; $i++) {
            self::spend($ad, self::day($lastOffset - $i), $perDay, $more);
        }
    }

    /** @param  list<int>  $variantStocks  one variant per entry, titled S, M, L, ... */
    public static function product(array $variantStocks = [5], float $price = 900, array $attrs = []): Product
    {
        $p = Product::factory()->create(array_merge(['title' => 'Abaya Noor', 'status' => 'active'], $attrs));
        foreach (array_values($variantStocks) as $i => $stock) {
            ProductVariant::factory()->create([
                'product_id' => $p->id, 'title' => self::SIZES[$i] ?? 'V'.$i, 'price' => $price, 'inventory_quantity' => $stock,
            ]);
        }

        return $p;
    }

    public static function link(Ad $ad, Product $p, array $attrs = []): AdMaterial
    {
        $m = AdMaterial::factory()->create(array_merge(['title' => 'Reel '.$p->title, 'product_id' => $p->id], $attrs));
        $m->ads()->attach($ad->id);

        return $m;
    }

    /** A confirmed store order credited to the ad (utm_ad), placed at a Cairo time. */
    public static function order(?Ad $ad, string $placedCairo, float $total, array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'status' => OrderStatus::Confirmed, 'source' => OrderSource::Store, 'subtotal' => $total, 'shipping_fee' => 0, 'total' => $total,
            'placed_at' => CarbonImmutable::parse($placedCairo, self::TZ)->utc(),
            'ad_id' => $ad?->id, 'ad_campaign_id' => $ad?->ad_campaign_id, 'ad_attribution' => $ad !== null ? 'utm_ad' : null,
        ], $attrs));
    }

    public static function referral(Ad $ad, string $atCairo, ?Conversation $c = null): ConversationAdReferral
    {
        $c ??= Conversation::factory()->create();

        return ConversationAdReferral::query()->create([
            'conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'ad_external_id' => (string) $ad->external_id,
            'referred_at' => CarbonImmutable::parse($atCairo, self::TZ)->utc(),
        ]);
    }

    /**
     * Binds a ChatSignals that answers from fixed rows: $byAd[ad id] = partial funnel row (chats, orders, reasons...).
     * $window(list<int> $ids, string $from, string $to) may return rows for a given window; null falls back to $byAd.
     *
     * @param  array<int, array<string, mixed>>  $byAd
     */
    public static function fakeChats(array $byAd, ?Closure $window = null): void
    {
        app()->instance(ChatSignals::class, new class($byAd, $window) extends ChatSignals
        {
            public function __construct(private array $byAd, private ?Closure $window) {}

            public function forAds(array $adIds, string $from, string $to): array
            {
                if ($this->window !== null && ($rows = ($this->window)($adIds, $from, $to)) !== null) {
                    return $rows;
                }
                $out = [];
                foreach ($adIds as $id) {
                    if (isset($this->byAd[$id])) {
                        $out[$id] = array_merge(ChatSignals::empty(), $this->byAd[$id]);
                    }
                }

                return $out;
            }
        });
    }
}
