<?php

namespace App\Shopify\Connection;

use Illuminate\Database\Eloquent\Model;

class ShopifyIntegration extends Model
{
    /**
     * The demo/seeder-only shop domain (`Database\Seeders\DemoSeeder::seedShopifyCatalog()`).
     * `HttpShopifyTransport` refuses any request whose host is this domain, and the
     * scheduled Shopify jobs (`ReconcileShopify`, `CheckShopifyWebhooks`) skip an
     * integration with this domain outright — so a leftover demo row can never
     * cause a real HTTP call, even if `crm.shopify.driver`/`crm.drivers.commerce`
     * were ever misconfigured as live in the same process.
     */
    public const DEMO_SHOP_DOMAIN = 'demo-store.myshopify.com';

    protected $table = 'shopify_integrations';

    protected $fillable = [
        'shop_domain',
        'shop_name',
        'currency',
        'access_token',
        'api_secret',
        'api_version',
        'granted_scopes',
        'status',
        'last_error',
        'connected_at',
        'settings',
        'import_state',
    ];

    /**
     * Default values merged with any settings actually stored, so callers
     * never have to null-check individual keys (spec §3.2).
     */
    private const SETTINGS_DEFAULTS = [
        'default_shipping_fee' => 60.0,
        'auto_create_shipment' => true,
        'stuck_order_days' => 5,
        'mismatch_alerts' => true,
        'order_creation_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'api_secret' => 'encrypted',
            'granted_scopes' => 'array',
            'settings' => 'array',
            'import_state' => 'array',
            'connected_at' => 'datetime',
        ];
    }

    public function settingsWithDefaults(): array
    {
        return array_merge(self::SETTINGS_DEFAULTS, $this->settings ?? []);
    }
}
