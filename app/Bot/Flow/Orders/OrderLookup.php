<?php

namespace App\Bot\Flow\Orders;

use App\Bot\ArabicNormalizer;
use App\Commerce\OrderStatusResolver;
use App\Models\Conversation;
use App\Models\Order;
use App\Shopify\Customers\PhoneNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Finds the customer's order (spec §2.1, §3): order number first, then phone,
 * then email. The OMS status wins when it has one; otherwise the local
 * Shopify/carrier data is mapped. A phone or email lookup only returns orders
 * whose shipping phone or customer phone/email matches the value given, so one
 * customer can never read another's orders.
 */
class OrderLookup
{
    public const CANCEL_WINDOW_MINUTES = 120;

    /** Shopify province codes that count as main cities for the delivery-time policy. */
    private const PROVINCES = ['C' => 'القاهرة', 'GZ' => 'الجيزة', 'ALX' => 'الإسكندرية'];

    private const MAX_LISTED = 3;

    private const MAX_SNAPSHOTS = 10;

    /** Local states the OMS cannot change any more: it is not asked about them. */
    private const FINAL_STATES = ['delivered', 'cancelled'];

    /** Set after the first OMS failure of one find(): the rest of that lookup uses local data. */
    private bool $omsDown = false;

    public function __construct(
        private readonly OmsClient $oms,
        private readonly OrderStatusResolver $resolver,
    ) {}

    /**
     * @param  array<string, mixed>  $entities  order_ref, phone, email, governorate (others ignored)
     * @return array{status:'found'|'multiple'|'not_found'|'missing_details', snapshots:list<OrderSnapshot>}
     */
    public function find(Conversation $c, array $entities): array
    {
        $this->omsDown = false;

        $ref = $this->ref($entities['order_ref'] ?? null);
        $phone = is_string($entities['phone'] ?? null) && trim($entities['phone']) !== '' ? PhoneNormalizer::toE164($entities['phone']) : null;
        $email = is_string($entities['email'] ?? null) && trim($entities['email']) !== '' ? mb_strtolower(trim($entities['email'])) : null;

        if ($ref === null && $phone === null && $email === null) {
            return ['status' => 'missing_details', 'snapshots' => []];
        }

        if ($ref !== null) {
            $order = Order::query()
                ->where(fn ($q) => $q->where('order_number', $ref)->orWhereIn('shopify_order_name', ['#'.$ref, $ref]))
                ->orderByDesc('created_at')->orderByDesc('id')
                ->first();

            if ($order !== null) {
                // An order number alone is not proof of ownership: the tracking link only goes to its owner.
                return ['status' => 'found', 'snapshots' => [$this->snapshot($order, $entities, $this->belongsToAsker($order, $c, $phone, $email))]];
            }
        }

        foreach ([[$phone, fn () => $this->byPhone((string) $phone)], [$email, fn () => $this->byEmail((string) $email)]] as [$value, $query]) {
            if ($value === null) {
                continue;
            }

            $orders = $query();

            if ($orders->isNotEmpty()) {
                return $this->pick($orders, $entities);
            }
        }

        return ['status' => 'not_found', 'snapshots' => []];
    }

    /** Minutes left of the 2-hour cancel/edit window (0 when over). */
    public function cancelWindowLeftMinutes(OrderSnapshot $s, CarbonImmutable $now): int
    {
        $elapsed = (int) floor($s->placedAt->diffInMinutes($now));

        return max(0, min(self::CANCEL_WINDOW_MINUTES, self::CANCEL_WINDOW_MINUTES - $elapsed));
    }

    private function ref(mixed $raw): ?string
    {
        if (! is_scalar($raw)) {
            return null;
        }

        $ref = preg_replace('/[\s#]+/u', '', (new ArabicNormalizer)->digitsToLatin((string) $raw)) ?? '';

        return $ref === '' ? null : $ref;
    }

    /** @return Collection<int, Order> most recent first, only orders whose phone really matches */
    private function byPhone(string $e164): Collection
    {
        $digits = preg_replace('/\D+/', '', $e164) ?? '';

        if (strlen($digits) < 8) {
            return collect();
        }

        $tail = '%'.substr($digits, -9);

        return Order::query()
            ->with('customer')
            ->where(fn ($q) => $q->where('shipping_phone', 'like', $tail)
                ->orWhereHas('customer', fn ($c) => $c->where('normalized_phone', $e164)->orWhere('phone', 'like', $tail)))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(50)
            ->get()
            ->filter(fn (Order $o) => PhoneNormalizer::toE164($o->shipping_phone) === $e164
                || $o->customer?->normalized_phone === $e164
                || PhoneNormalizer::toE164($o->customer?->phone) === $e164)
            ->values();
    }

    /** @return Collection<int, Order> */
    private function byEmail(string $email): Collection
    {
        return Order::query()
            ->whereHas('customer', fn ($c) => $c->whereRaw('lower(email) = ?', [$email]))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * Spec §3: several open orders → multiple (up to 3); one open → found; none open → the most
     * recent non-cancelled order; a cancelled one only when every match is cancelled.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function pick(Collection $orders, array $entities): array
    {
        // Each snapshot may call the OMS: only the most recent orders are considered.
        $snapshots = $orders->take(self::MAX_SNAPSHOTS)->map(fn (Order $o) => $this->snapshot($o, $entities))->values();
        $notCancelled = $snapshots->reject(fn (OrderSnapshot $s) => $s->statusKey === 'cancelled')->values();
        $open = $notCancelled->reject(fn (OrderSnapshot $s) => $s->statusKey === 'delivered')->values();

        if ($open->count() > 1) {
            return ['status' => 'multiple', 'snapshots' => $open->take(self::MAX_LISTED)->all()];
        }

        return ['status' => 'found', 'snapshots' => [$open->first() ?? $notCancelled->first() ?? $snapshots->first()]];
    }

    /**
     * Final fix wave I7: the conversation's own customer, or a phone/email she gave in this
     * conversation that matches the order's.
     */
    private function belongsToAsker(Order $o, Conversation $c, ?string $phone, ?string $email): bool
    {
        if ($c->customer_id !== null && (int) $o->customer_id === (int) $c->customer_id) {
            return true;
        }

        if ($phone !== null && in_array($phone, [PhoneNormalizer::toE164($o->shipping_phone), $o->customer?->normalized_phone, PhoneNormalizer::toE164($o->customer?->phone)], true)) {
            return true;
        }

        return $email !== null && $email === mb_strtolower(trim((string) $o->customer?->email));
    }

    /** @param  bool  $withTrackingUrl  false hides the carrier link (the asker is not the order's owner) */
    private function snapshot(Order $o, array $entities, bool $withTrackingUrl = true): OrderSnapshot
    {
        $number = $o->shopify_order_name ?: (string) ($o->order_number ?: $o->id);
        $number = '#'.ltrim($number, '#');
        [$localKey, $failedAttempt] = $this->shopifyState($o);
        $source = 'shopify';
        $oms = null;

        if (! in_array($localKey, self::FINAL_STATES, true)) {
            if ($this->omsDown) {
                $source = 'shopify_fallback';
            } else {
                try {
                    $oms = $this->oms->status(ltrim((string) ($o->order_number ?: $number), '#'));
                } catch (Throwable $e) {
                    report($e);
                    $this->omsDown = true;
                    $source = 'shopify_fallback';
                }
            }
        }

        if ($oms !== null) {
            $source = 'oms';
            $key = $oms->state;
            $failedAttempt = false;
            $url = $oms->trackingUrl ?? $this->trackingUrl($o);
        } else {
            $key = $localKey;
            $url = $this->trackingUrl($o);
        }

        return new OrderSnapshot(
            $o->id,
            $number,
            // Imported Shopify orders carry the store's creation time; created_at is the import time.
            CarbonImmutable::instance($o->placed_at ?? $o->created_at ?? now()),
            $source,
            $key,
            $withTrackingUrl ? $url : null,
            $this->governorate($o, $entities),
            $failedAttempt,
        );
    }

    /** @return array{0:string, 1:bool} status key, failed delivery attempt */
    private function shopifyState(Order $o): array
    {
        if ($o->cancelled_at !== null) {
            return ['cancelled', false];
        }

        return match ($this->resolver->resolve($o)->shipmentStep) {
            'created' => ['confirmed', false],
            'picked_up', 'in_transit' => ['shipped', false],
            'out_for_delivery' => ['on_the_way', false],
            'failed_attempt' => ['on_the_way', true],
            'delivered' => ['delivered', false],
            'returned' => ['returned', false],
            'cancelled' => ['cancelled', false],
            default => [in_array($o->fulfillment_status, ['fulfilled', 'partial'], true) ? 'shipped' : 'confirmed', false],
        };
    }

    private function trackingUrl(Order $o): ?string
    {
        return $o->fulfillments()
            ->whereNotNull('tracking_url')->where('tracking_url', '!=', '')
            ->orderByDesc('shopify_created_at')->orderByDesc('id')
            ->value('tracking_url');
    }

    /** Province code first; without a code, the city or the governorate the customer named. */
    private function governorate(Order $o, array $entities): ?string
    {
        $code = strtoupper(trim((string) $o->shipping_province_code));
        $said = is_string($entities['governorate'] ?? null) ? trim($entities['governorate']) : '';

        if ($code !== '') {
            return self::PROVINCES[$code] ?? ($o->shipping_city ?: null);
        }

        return OrderStatusText::mainCity($o->shipping_city)
            ?? OrderStatusText::mainCity($said)
            ?? ($o->shipping_city ?: ($said !== '' ? $said : null));
    }
}
