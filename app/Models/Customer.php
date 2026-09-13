<?php

namespace App\Models;

use App\Shopify\Customers\PhoneNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'normalized_phone',
        'email',
        'city',
        'address',
        'avatar_url',
        'notes',
        'shopify_customer_id',
        'tags',
        'accepts_marketing',
        'orders_count',
        'total_spent',
        'shopify_orders_count',
        'shopify_total_spent',
        'shopify_updated_at',
        'is_repeat',
        'has_open_order',
        'has_return',
        'has_stuck_order',
        'last_contact_at',
    ];

    protected function casts(): array
    {
        return [
            'orders_count' => 'integer',
            'total_spent' => 'decimal:2',
            'tags' => 'array',
            'accepts_marketing' => 'boolean',
            'shopify_orders_count' => 'integer',
            'shopify_total_spent' => 'decimal:2',
            'shopify_updated_at' => 'datetime',
            'is_repeat' => 'boolean',
            'has_open_order' => 'boolean',
            'has_return' => 'boolean',
            'has_stuck_order' => 'boolean',
            'last_contact_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Customer $customer) {
            $customer->normalized_phone = PhoneNormalizer::toE164($customer->phone);
        });
    }

    /**
     * @return HasMany<CustomerIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(CustomerIdentity::class);
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<CustomerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /**
     * @return HasMany<CustomerMergeSuggestion, $this>
     */
    public function mergeSuggestions(): HasMany
    {
        return $this->hasMany(CustomerMergeSuggestion::class);
    }
}
