import TikTokConnectPanel from '@/components/crm/integrations/TikTokConnectPanel.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import ar from '@/i18n/ar';
import en from '@/i18n/en';
import type { TikTokSettings } from '@/types/admin';
import { mount } from '@vue/test-utils';
import { afterAll, describe, expect, it } from 'vitest';

afterAll(() => setCurrentLocale('ar'));

const settings: TikTokSettings = {
    enabled: true,
    app_id_set: true,
    app_secret_set: true,
    app_id: '7591714577012752400',
    connect_url: '/settings/channels/tiktok/connect',
    redirect_uri: 'https://crm.example.com/settings/channels/tiktok/callback/',
    callback_url: 'https://crm.example.com/webhooks/tiktok',
    scopes: ['message.list.read', 'message.list.send'],
    driver: 'live',
    flash: null,
};

describe('TikTokConnectPanel', () => {
    it('links to the OAuth start and shows the three portal values', () => {
        setCurrentLocale('en');
        const wrapper = mount(TikTokConnectPanel, { props: { settings, connected: false } });

        const link = wrapper.find('a[href="/settings/channels/tiktok/connect"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('Connect with TikTok');
        expect(wrapper.text()).toContain('https://crm.example.com/webhooks/tiktok');
        expect(wrapper.text()).toContain('https://crm.example.com/settings/channels/tiktok/callback/');
        expect(wrapper.text()).toContain('message.list.read, message.list.send');
        expect(wrapper.text()).toContain('7591714577012752400');
    });

    it('says reconnect once connected, and warns instead of linking when the app keys are missing', () => {
        setCurrentLocale('en');
        const connected = mount(TikTokConnectPanel, { props: { settings, connected: true } });
        expect(connected.find('a').text()).toContain('Reconnect');

        const missing = mount(TikTokConnectPanel, { props: { settings: { ...settings, enabled: false }, connected: false } });
        expect(missing.find('a[href="/settings/channels/tiktok/connect"]').exists()).toBe(false);
        expect(missing.find('[role="alert"]').text()).toContain('TIKTOK_APP_ID');
    });

    it('flags the fake driver so a test server is never mistaken for the real TikTok', () => {
        setCurrentLocale('en');
        const wrapper = mount(TikTokConnectPanel, { props: { settings: { ...settings, driver: 'fake' }, connected: false } });
        expect(wrapper.text()).toContain('nothing is sent to the real TikTok');
    });

    it.each(['ar', 'en'] as const)('has every TikTok string in %s', (locale) => {
        const dict = (locale === 'ar' ? ar : en).settings.integrations as unknown as {
            tiktok: Record<string, unknown> & { flash: Record<string, string> };
            checks: Record<string, string>;
            codes: Record<string, string>;
            errors: Record<string, string>;
            disconnect_dialog: Record<string, string>;
        };
        for (const key of ['title', 'what', 'connect', 'keys_missing', 'paste_toggle', 'paste_send', 'portal_title', 'webhook_url', 'redirect_url', 'scopes', 'step_1', 'step_5']) {
            expect(typeof dict.tiktok[key], `${locale}: tiktok.${key}`).toBe('string');
        }
        for (const code of ['connected', 'subscribe_failed', 'cancelled', 'state_mismatch', 'no_code', 'code_invalid', 'unreachable', 'app_id_missing', 'app_secret_missing']) {
            expect(typeof dict.tiktok.flash[code], `${locale}: flash.${code}`).toBe('string');
        }
        expect(typeof dict.checks.account).toBe('string');
        for (const code of ['refresh_token_expired', 'no_advanced_access', 'account_ok']) expect(typeof dict.codes[code], code).toBe('string');
        for (const code of ['tiktok_code_invalid', 'tiktok_unreachable']) expect(typeof dict.errors[code], code).toBe('string');
        expect(typeof dict.disconnect_dialog.tiktok).toBe('string');
    });
});
