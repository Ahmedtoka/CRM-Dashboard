import { translate } from '@/i18n';
import { hubItemLabel, hubItemState } from '@/lib/settingsHub';
import { describe, expect, it } from 'vitest';

const t = (key: string, params?: Record<string, string | number>) => translate('ar', key, params);

describe('hubItemState', () => {
    it('says each item’s state in Arabic digits', () => {
        expect(hubItemState({ id: 'tags', href: '/settings/tags', count: 18 }, t)).toEqual({ text: '١٨ وسم' });
        expect(hubItemState({ id: 'quick_replies', href: '/settings/quick-replies', shared: 24, mine: 6 }, t)?.text).toBe('٢٤ مشتركة · ٦ بتاعتي');
        expect(hubItemState({ id: 'onboarding', href: '/onboarding', done: 3, total: 5, tone: 'warn' }, t)).toEqual({ text: 'خلصت ٣ من ٥', tone: 'warn' });
    });

    it('turns a broken connection into words and a red dot', () => {
        expect(hubItemState({ id: 'integrations', href: '/settings/integrations', count: 3, tone: 'danger' }, t)).toEqual({
            text: 'فيه قناة محتاجة تتربط تاني',
            tone: 'danger',
        });
        expect(hubItemState({ id: 'shopify', href: '/settings/shopify', state: 'off', tone: 'off' }, t)).toEqual({ text: 'مش مربوط', tone: 'off' });
    });

    it('keeps the shop domain as it is (Latin, copied elsewhere)', () => {
        expect(hubItemState({ id: 'shopify', href: '/settings/shopify', state: 'connected', text: 'levoile.myshopify.com', tone: 'ok' }, t)?.text).toBe(
            'levoile.myshopify.com',
        );
    });

    it('calls a moderator\'s replies hers', () => {
        const item = { id: 'quick_replies', href: '/settings/quick-replies', mine: 8, personal: true };
        expect(hubItemLabel(item)).toBe('settings_hub.items.my_quick_replies');
        expect(hubItemState(item, t)?.text).toBe('٨ من ردودي');
        expect(hubItemLabel({ id: 'tags', href: '/settings/tags' })).toBe('settings_hub.items.tags');
    });

    it('has no line for an item without state', () => {
        expect(hubItemState({ id: 'ad_rules', href: '/settings/ads/rules' }, t)).toBeNull();
    });
});
