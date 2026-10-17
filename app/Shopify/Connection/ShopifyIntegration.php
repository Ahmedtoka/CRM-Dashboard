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

    /** Connected with the token pasted on Settings → Shopify (the store's own legacy custom app). */
    public const MODE_CUSTOM_TOKEN = 'custom_token';

    /** Connected through a Partners app (managed install + token exchange, expiring offline tokens). */
    public const MODE_APP = 'app';

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
        'auth_mode',
        'app_client_id',
        'access_token_expires_at',
        'refresh_token',
        'refresh_token_expires_at',
        'installed_at',
        'uninstalled_at',
        'legacy_credentials',
    ];

    /**
     * Default values merged with any settings actually stored, so callers
     * never have to null-check individual keys (spec §3.2).
     */
    private const SETTINGS_DEFAULTS = [
        'default_shipping_fee' => 60.0,
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
            'refresh_token' => 'encrypted',
            // {access_token, api_secret} of the manual connection, kept for the one-click rollback.
            'legacy_credentials' => 'encrypted:array',
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
    }

    public function usesApp(): bool
    {
        return $this->auth_mode === self::MODE_APP;
    }

    public function settingsWithDefaults(): array
    {
        return array_merge(self::SETTINGS_DEFAULTS, $this->settings ?? []);
    }
}
