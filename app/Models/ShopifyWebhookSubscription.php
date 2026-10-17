<?php

namespace App\Models;

use Database\Factories\ShopifyWebhookSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyWebhookSubscription extends Model
{
    /** @use HasFactory<ShopifyWebhookSubscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'topic',
        'shopify_subscription_id',
        'callback_url',
        'last_received_at',
        'registered_at',
        // 'api' (registered by WebhookRegistrar for the manual connection) | 'config' (shopify.app.toml).
        'managed_by',
    ];

    protected function casts(): array
    {
        return [
            'last_received_at' => 'datetime',
            'registered_at' => 'datetime',
        ];
    }
}
