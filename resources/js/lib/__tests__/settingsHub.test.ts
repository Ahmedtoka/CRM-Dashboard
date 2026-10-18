import { translate } from '@/i18n';
import { hubItemLabel, hubItemState, hubLocate, hubNav, settingsCrumbs } from '@/lib/settingsHub';
import { describe, expect, it } from 'vitest';

const t = (key: string, params?: Record<string, string | number>) => translate('ar', key, params);

describe('hubItemState', () => {
    it('says each item’s state in Arabic digits', () => {
        expect(hubItemState({ id: 'tags', href: '/settings/tags', count: 18 }, t)).toEqual({ text: '١٨ وسم' });
        expect(hubItemState({ id: 'quick_replies', href: '/settings/quick-replies', shared: 24, mine: 6 }, t)?.text).toBe('٢٤ مشتركة · ٦ بتاعتي');
        expect(hubItemState({ id: 'onboarding', href: '/onboarding', done: 3, total: 5, tone: 'warn' }, t)).toEqual({
            text: 'خلصت ٣ من ٥',
            tone: 'warn',
        });
    });

    it('turns a broken connection into words and a red dot', () => {
        expect(hubItemState({ id: 'integrations', href: '/settings/integrations', count: 3, tone: 'danger' }, t)).toEqual({
            text: 'فيه قناة محتاجة تتربط تاني',
            tone: 'danger',
        });
        expect(hubItemState({ id: 'shopify', href: '/settings/shopify', state: 'off', tone: 'off' }, t)).toEqual({ text: 'مش مربوط', tone: 'off' });
    });

    it('keeps the shop domain as it is (Latin, copied elsewhere)', () => {
        expect(
            hubItemState({ id: 'shopify', href: '/settings/shopify', state: 'connected', text: 'levoile.myshopify.com', tone: 'ok' }, t)?.text,
        ).toBe('levoile.myshopify.com');
    });

    it("calls a moderator's replies hers", () => {
        const item = { id: 'quick_replies', href: '/settings/quick-replies', mine: 8, personal: true };
        expect(hubItemLabel(item)).toBe('settings_hub.items.my_quick_replies');
        expect(hubItemState(item, t)?.text).toBe('٨ من ردودي');
        expect(hubItemLabel({ id: 'tags', href: '/settings/tags' })).toBe('settings_hub.items.tags');
    });

    it('has no line for an item without state', () => {
        expect(hubItemState({ id: 'ad_rules', href: '/settings/ads/rules' }, t)).toBeNull();
    });
});

const ids = (groups: ReturnType<typeof hubNav>) => groups.map((g) => g.id);
const items = (groups: ReturnType<typeof hubNav>, id: string) => groups.find((g) => g.id === id)?.items.map((i) => i.id);

describe('hubNav', () => {
    it('gives the admin every group, the dev tools only when they are on', () => {
        expect(ids(hubNav('admin'))).toEqual(['account', 'team', 'channels', 'store', 'ads', 'start']);
        expect(ids(hubNav('admin', true))).toEqual(['account', 'team', 'channels', 'store', 'ads', 'start', 'dev']);
        expect(items(hubNav('admin'), 'team')).toEqual(['users', 'queue', 'tags']);
    });

    it('gives a supervisor the team (without users) and the ads', () => {
        const groups = hubNav('supervisor', true);
        expect(ids(groups)).toEqual(['account', 'team', 'ads']);
        expect(items(groups, 'team')).toEqual(['queue', 'tags']);
        expect(groups[0].items.find((i) => i.id === 'quick_replies')?.personal).toBe(false);
    });

    it('gives a moderator her account and her own replies only', () => {
        const groups = hubNav('moderator', true);
        expect(ids(groups)).toEqual(['account']);
        expect(groups[0].items).toEqual([
            expect.objectContaining({ id: 'profile' }),
            expect.objectContaining({ id: 'quick_replies', personal: true }),
        ]);
    });

    it('gives a media buyer the account without saved replies', () => {
        expect(ids(hubNav('media_buyer'))).toEqual(['account']);
        expect(items(hubNav('media_buyer'), 'account')).toEqual(['profile']);
        expect(items(hubNav('content'), 'account')).toEqual(['profile']);
    });
});

describe('hubLocate', () => {
    const groups = hubNav('admin', true);

    it('finds the item a tab or child page belongs to', () => {
        expect(hubLocate(groups, '/settings/password')?.item.id).toBe('profile');
        expect(hubLocate(groups, '/settings/password')?.group.id).toBe('account');
        expect(hubLocate(groups, '/settings/channels/facebook/pages')?.item.id).toBe('integrations');
        expect(hubLocate(groups, '/settings/tags?page=2')?.item.id).toBe('tags');
        expect(hubLocate(groups, '/onboarding')?.group.id).toBe('start');
    });

    it('is null on the hub itself and outside settings', () => {
        expect(hubLocate(groups, '/settings')).toBeNull();
        expect(hubLocate(groups, '/inbox')).toBeNull();
        expect(hubLocate(hubNav('admin'), '/simulator')).toBeNull();
    });
});

describe('settingsCrumbs', () => {
    it('reads «الإعدادات › المجموعة › الصفحة»', () => {
        expect(settingsCrumbs(t, 'team', 'الوسوم')).toEqual([
            { title: 'الإعدادات', href: '/settings' },
            { title: 'الفريق والمحادثات', href: '/settings#settings-team' },
            { title: 'الوسوم' },
        ]);
    });

    it('skips the group when the URL has none, or when it is the page itself', () => {
        expect(settingsCrumbs(t, null, 'X')).toEqual([{ title: 'الإعدادات', href: '/settings' }, { title: 'X' }]);
        expect(settingsCrumbs(t, 'start', 'ابدأ من هنا')).toEqual([{ title: 'الإعدادات', href: '/settings' }, { title: 'ابدأ من هنا' }]);
        expect(settingsCrumbs(t, 'account', 'كلمة السر')).toHaveLength(3);
    });
});
