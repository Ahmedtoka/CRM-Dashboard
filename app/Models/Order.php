<?php

namespace App\Models;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Platform;
use Database\Factories\OrderFactory;
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
            'shopify_updated_at' => 'datetime',
            'submit_attempts' => 'integer',
            'mismatch' => 'boolean',
            'mismatch_notified_reasons' => 'array',
        ];
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
