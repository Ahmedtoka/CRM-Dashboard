<?php

namespace App\Models;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Platform;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'conversation_id',
        'created_by_id',
        'platform',
        'type',
        'shopify_order_id',
        'shopify_draft_order_id',
        'order_number',
        'invoice_url',
        'status',
        'financial_status',
        'fulfillment_status',
        'subtotal',
        'shipping_fee',
        'discount',
        'total',
        'currency',
        'shipping_name',
        'shipping_phone',
        'billing_phone',
        'shipping_city',
        'shipping_address',
        'note',
        'paid_at',
        'source',
        'shopify_order_name',
        'cancelled_at',
        'placed_at',
        'cancel_reason',
        'shopify_updated_at',
        'idempotency_key',
        'submit_attempts',
        'last_error',
        'shipping_rate_id',
        'shipping_title',
        'discount_reason',
        'mismatch',
        'mismatch_reason',
        'shipping_province_code',
        'discount_type',
        'discount_value',
        'shipping_province',
        'payment_gateway',
        'tags',
        'shipment_status',
        'delivered_at',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'landing_site',
        'ad_id',
        'ad_campaign_id',
        'ad_attribution',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'type' => OrderType::class,
            'status' => OrderStatus::class,
            'source' => OrderSource::class,
            'subtotal' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'discount' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'placed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'shopify_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'submit_attempts' => 'integer',
            'mismatch' => 'boolean',
            'mismatch_notified_reasons' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Ad, $this>
     */
    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }

    /**
     * Orders whose Shopify state can still change, so the scheduled and on-view
     * refreshes keep reading them (spec §3.2, R8). An order is FINAL, and never
     * auto-refreshed, when any of these holds:
     *  - it is cancelled (`cancelled_at` set);
     *  - its payment is `refunded` or `voided`;
     *  - it is delivered: `delivered_at` set, or `fulfillment_status = fulfilled`
     *    AND `shipment_status = delivered`.
     * A NULL column never makes an order final (every test is written NULL-safe).
     *
     * @param  Builder<Order>  $query
     */
    public function scopeOpenForSync(Builder $query): void
    {
        $query->whereNull('cancelled_at')
            ->whereNull('delivered_at')
            ->where(fn (Builder $q) => $q->whereNull('financial_status')->orWhereNotIn('financial_status', ['refunded', 'voided']))
            ->where(fn (Builder $q) => $q->whereNull('fulfillment_status')
                ->orWhere('fulfillment_status', '!=', 'fulfilled')
                ->orWhereNull('shipment_status')
                ->orWhere('shipment_status', '!=', 'delivered'));
    }

    /**
     * Delivered according to Shopify (fresh-orders F4: the CRM keeps no carrier tracking): `delivered_at` set, the
     * order's `shipment_status` delivered, or one of its fulfillments delivered.
     *
     * @param  Builder<Order>  $query
     */
    public function scopeDeliveredOnShopify(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereNotNull('orders.delivered_at')
            ->orWhere('orders.shipment_status', 'delivered')
            ->orWhereHas('fulfillments', fn (Builder $f) => $f->where('shipment_status', 'delivered')));
    }

    /**
     * The row-level twin of scopeOpenForSync(): true when the order is FINAL
     * (cancelled, refunded/voided, or delivered) and is never auto-refreshed.
     * The list uses it to skip such rows in the on-view refresh.
     */
    public function isFinalForSync(): bool
    {
        return $this->cancelled_at !== null
            || $this->delivered_at !== null
            || in_array($this->financial_status, ['refunded', 'voided'], true)
            || ($this->fulfillment_status === 'fulfilled' && $this->shipment_status === 'delivered');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasOne<Shipment, $this>
     */
    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    /**
     * @return HasMany<Fulfillment, $this>
     */
    public function fulfillments(): HasMany
    {
        return $this->hasMany(Fulfillment::class);
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
