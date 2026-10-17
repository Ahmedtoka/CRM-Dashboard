import AppConnectionCard from '@/components/crm/shopify/AppConnectionCard.vue';
import type { ShopifyAppInfo, ShopifyIntegrationRow } from '@/types/admin';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

function integration(over: Partial<ShopifyIntegrationRow> = {}): ShopifyIntegrationRow {
    return {
        shop_domain: 'levoile.myshopify.com',
        shop_name: 'Le Voile',
        currency: 'EGP',
        status: 'connected',
        last_error: null,
        connected_at: null,
        settings: { default_shipping_fee: 60, stuck_order_days: 5, mismatch_alerts: true, order_creation_enabled: true },
        import_state: null,
        granted_scopes: [],
        missing_scopes: [],
        has_webhook_secret: true,
        auth_mode: 'app',
        access_token_expires_at: '2026-10-17T13:00:00Z',
        refresh_token_expires_at: '2027-01-15T12:00:00Z',
        installed_at: '2026-10-17T12:00:00Z',
        has_legacy_credentials: true,
        ...over,
    };
}

const app: ShopifyAppInfo = { configured: true, last_verified: { 'app:levoile': '2026-10-17T12:30:00Z' } };

function card(over: Partial<ShopifyIntegrationRow> = {}, info: ShopifyAppInfo = app) {
    return mount(AppConnectionCard, { props: { integration: integration(over), app: info, removing: false, rollingBack: false } });
}

afterEach(() => vi.restoreAllMocks());

describe('AppConnectionCard', () => {
    it('shows the app actions only on the app, and asks before each', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
        const wrapper = card();

        await wrapper.get('[data-testid="shopify-rollback"]').trigger('click');
        await wrapper.get('[data-testid="shopify-remove-legacy"]').trigger('click');

        expect(confirm).toHaveBeenCalledTimes(2);
        expect(wrapper.emitted('rollback')).toHaveLength(1);
        expect(wrapper.emitted('removeLegacy')).toHaveLength(1);
        expect(card({ auth_mode: 'custom_token' }).find('[data-testid="shopify-rollback"]').exists()).toBe(false);
    });

    it('does nothing when the confirmation is declined', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        const wrapper = card();

        await wrapper.get('[data-testid="shopify-rollback"]').trigger('click');

        expect(wrapper.emitted('rollback')).toBeUndefined();
    });

    it('disables the rollback without kept manual credentials', () => {
        expect(card({ has_legacy_credentials: false }).get('[data-testid="shopify-rollback"]').attributes('disabled')).toBeDefined();
    });

    it('tells the owner to reopen the app when the app connection is lost', () => {
        expect(card({ status: 'disconnected' }).find('[data-testid="shopify-app-reopen"]').exists()).toBe(true);
        expect(card().find('[data-testid="shopify-app-reopen"]').exists()).toBe(false);
    });

    it('lists when the app and the old secret last verified a webhook', () => {
        const wrapper = card();

        expect(wrapper.find('[data-testid="shopify-verified-app"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="shopify-verified-legacy"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="shopify-verified-env"]').exists()).toBe(false);
    });
});
