import { translate } from '@/i18n';
import { buildNav, groupBadge, type NavContext } from '@/lib/navMenu';
import type { NavItem } from '@/types';
import { describe, expect, it } from 'vitest';

const t = (key: string, params?: Record<string, string | number>) => translate('ar', key, params);

function ctx(over: Partial<NavContext> = {}): NavContext {
    return { role: 'admin', canSeeBoard: true, devTools: false, onboarding: null, badges: {}, adsQuery: '', t, ...over };
}

const titles = (items: NavItem[]) => items.map((i) => (i.separator ? '---' : i.title));
const hrefs = (item: NavItem | undefined) => (item?.children ?? []).map((c) => c.href);
const group = (items: NavItem[], href: string) => items.find((i) => i.href === href);

describe('buildNav (IA §3.4)', () => {
    it('gives the admin seven sections in the order of the day', () => {
        const items = buildNav(ctx());

        expect(titles(items)).toEqual(['النهارده', 'المحادثات', 'الأوردرات', 'الإعلانات', 'التقارير', 'البوت', 'الإعدادات']);
        expect(group(items, '/today')?.children).toBeUndefined();
        expect(hrefs(items[1])).toEqual(['/inbox', '/comments', '/board']);
        expect(titles(items[1].children!)).toEqual(['الرسايل', 'الكومنتات', 'الصالة']);
        expect(hrefs(items[2])).toEqual(['/orders', '/cases', '/customers']);
        expect(titles(items[2].children!)).toEqual(['الأوردرات', 'ما بعد البيع', 'العملاء']);
        expect(hrefs(items[3])).toEqual(['/ads/decisions', '/ads/launches', '/ads/explorer', '/ads/numbers', '/ads/materials']);
        expect(titles(items[3].children!)).toEqual(['محتاج قرار', 'الإطلاقات', 'كل الإعلانات', 'الأرقام', 'المكتبة']);
        expect(hrefs(items[4])).toEqual(['/reports/team', '/reports/activity']);
        expect(titles(items[4].children!)).toEqual(['الفريق', 'السجل']);
        expect(hrefs(items[5])).toEqual(['/bot', '/bot/brain', '/bot/flows', '/bot/replies', '/bot/test-links', '/bot/learning', '/bot/performance']);
        expect(titles(items[5].children!)).toEqual(['التشغيل', 'عقل البوت', 'الفلوهات', 'كلام البوت', 'التجربة', 'التعلم', 'الأداء']);
        expect(items[6].href).toBe('/settings');
    });

    it('puts «ابدأ من هنا» first with the progress until the admin finishes', () => {
        const items = buildNav(ctx({ onboarding: { done: 3, total: 5 } }));

        expect(items[0].href).toBe('/onboarding');
        expect(items[0].progress).toEqual({ done: 3, total: 5 });
        expect(buildNav(ctx({ onboarding: { done: 5, total: 5 } }))[0].href).toBe('/today');
        expect(buildNav(ctx({ role: 'supervisor', onboarding: { done: 1, total: 5 } }))[0].href).toBe('/today');
    });

    it('never puts the developer pages in the sidebar (they live in the settings hub)', () => {
        const all = buildNav(ctx({ devTools: true })).flatMap((i) => [i.href, ...hrefs(i)]);

        expect(all).not.toContain('/simulator');
        expect(all).not.toContain('/reports/latency');
    });

    it('gives the supervisor the admin menu', () => {
        expect(titles(buildNav(ctx({ role: 'supervisor' })))).toEqual(titles(buildNav(ctx())));
    });

    it('gives the moderator six flat links, then settings', () => {
        const items = buildNav(ctx({ role: 'moderator', canSeeBoard: false }));

        expect(titles(items)).toEqual(['الرسايل', 'الكومنتات', 'التأكيدات', 'الأوردرات', 'ما بعد البيع', 'أدائي', '---', 'الإعدادات']);
        expect(items.every((i) => !i.children)).toBe(true);
        expect(items.map((i) => i.href).slice(0, 6)).toEqual(['/inbox', '/comments', '/orders/confirmations', '/orders', '/cases', '/reports/me']);
        expect(items[3].exclude).toEqual(['/orders/confirmations']);
    });

    it('adds «الصالة» under the comments for the shift leader', () => {
        const items = buildNav(ctx({ role: 'moderator', canSeeBoard: true }));

        expect(titles(items).slice(0, 3)).toEqual(['الرسايل', 'الكومنتات', 'الصالة']);
    });

    it('gives the media buyer «النهارده» = today’s ads and the launches link', () => {
        const items = buildNav(ctx({ role: 'media_buyer', badges: { launches: 2 } }));

        expect(titles(items)).toEqual(['النهارده', 'محتاج قرار', 'الإطلاقات', 'كل الإعلانات', 'الأرقام', 'المكتبة', '---', 'الإعدادات']);
        expect(items[0].href).toBe('/today/ads');
        expect(items[2].badge).toBe(2);
    });

    it('gives content the library and «إطلاقاتي»', () => {
        const items = buildNav(ctx({ role: 'content', badges: { launches_content: 1 } }));

        expect(titles(items)).toEqual(['المكتبة', 'إطلاقاتي', '---', 'الإعدادات']);
        expect(items[0].match).toEqual(['/ads/collections', '/ads/stock']);
        expect(items[1].badge).toBe(1);
    });

    it('carries the shared ads filters on the report links only', () => {
        const ads = buildNav(ctx({ adsQuery: '?range=7d' }))[3];

        expect(ads.children!.find((c) => c.href === '/ads/explorer')?.query).toBe('?range=7d');
        expect(ads.children!.find((c) => c.href === '/ads/materials')?.query).toBeUndefined();
    });

    it('lights each item for the pages merged under it', () => {
        const items = buildNav(ctx());
        const child = (href: string) => items.flatMap((i) => i.children ?? []).find((c) => c.href === href)!;

        expect(child('/orders').match).toContain('/orders/confirmations');
        expect(child('/ads/decisions').match).toContain('/ads/approvals');
        expect(child('/reports/team').match).toEqual(expect.arrayContaining(['/reports/queue', '/reports/quick-replies', '/reports/users']));
        expect(child('/bot/flows').match).toContain('/bot/intents');
        expect(child('/bot/replies').match).toEqual(expect.arrayContaining(['/bot/knowledge', '/bot/translations']));
        expect(child('/bot/test-links').match).toContain('/bot/test-sessions');
        expect(child('/bot').exact).toBe(true);
    });

    it('puts badges only on waiting work', () => {
        const items = buildNav(ctx({ badges: { confirmations: 4, cases: 2, cases_urgent: true, board: 3, learning: 9, decisions: 6, launches: 1 } }));
        const child = (href: string) => items.flatMap((i) => i.children ?? []).find((c) => c.href === href)!;

        expect(child('/orders').badge).toBe(4);
        expect(child('/cases')).toMatchObject({ badge: 2, badgeUrgent: true });
        expect(child('/board').badge).toBe(3);
        expect(child('/bot/learning').badge).toBe(9);
        expect(child('/ads/decisions').badge).toBe(6);
        expect(child('/customers').badge).toBeUndefined();
    });
});

describe('groupBadge', () => {
    it('sums the children and turns red when any child is late', () => {
        const items = buildNav(ctx({ badges: { confirmations: 4, cases: 2, cases_urgent: true } }));

        expect(groupBadge(items[2])).toEqual({ count: 6, urgent: true });
        expect(groupBadge(items[4])).toEqual({ count: 0, urgent: false });
    });
});
