<?php

namespace App\Shopify\Connection;

use App\Enums\UserRole;
use App\Events\UserNotified;
use App\Models\User;
use App\Shopify\Client\ShopifyException;
use App\Support\SafeBroadcast;
use Illuminate\Support\Str;

/**
 * Reads/writes the single encrypted Shopify integration record (spec §2, §3.2).
 */
final class IntegrationRepository
{
    public function current(): ?ShopifyIntegration
    {
        return ShopifyIntegration::query()->oldest('id')->first();
    }

    public function requireConnected(): ShopifyIntegration
    {
        $integration = $this->current();

        if ($integration === null || $integration->status !== 'connected') {
            throw new ShopifyException('not_connected', 'No connected Shopify integration');
        }

        return $integration;
    }

    /**
     * Flags the saved integration as broken and notifies admins, but only on
     * the transition into 'error' (spec §4.4, mirrors ChannelHealth).
     */
    public function markError(string $message): void
    {
        $integration = $this->current();

        if ($integration === null) {
            return;
        }

        $wasError = $integration->status === 'error';

        $integration->forceFill([
            'status' => 'error',
            'last_error' => Str::limit($message, 250),
        ])->save();

        if (! $wasError) {
            $this->notifyAdmins($integration);
        }
    }

    public function markConnected(): void
    {
        $integration = $this->current();

        if ($integration === null) {
            return;
        }

        $integration->forceFill([
            'status' => 'connected',
            'last_error' => null,
            'connected_at' => now(),
        ])->save();
    }

    private function notifyAdmins(ShopifyIntegration $integration): void
    {
        User::query()
            ->where('is_active', true)
            ->where('role', UserRole::Admin->value)
            ->get()
            ->each(fn (User $u) => SafeBroadcast::send(new UserNotified($u->id, 'shopify.error', [
                'shop_domain' => $integration->shop_domain,
                'error' => $integration->last_error,
            ])));
    }
}
