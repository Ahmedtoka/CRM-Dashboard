import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), visit: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { platforms: [] }, url: '/orders' }),
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import OrderSourceChip from '@/components/crm/orders/OrderSourceChip.vue';
import OrdersAdsTab from '@/components/crm/orders/OrdersAdsTab.vue';
import OrdersAnalyticsTab from '@/components/crm/orders/OrdersAnalyticsTab.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import type { OrdersAnalytics, OrdersByAdRow } from '@/types/orders';

beforeAll(() => setCurrentLocale('ar'));

const source = {
    id: 7,
    name: 'اسدال كتان',
    thumbnail_url: null,
    campaign: 'خريف',
    attribution: 'utm_ad',
    platform: 'meta',
    external_id: '123',
    manager_url: 'https://www.facebook.com/adsmanager/manage/ads?selected_ad_ids=123',
};

describe('OrderSourceChip', () => {
    it('opens the ad drawer and links to Meta for users with ads access', async () => {
        const w = mount(OrderSourceChip, { props: { source, canOpenAds: true } });
        await w.find('[data-open-ad]').trigger('click');
        expect(w.emitted('open')?.[0]).toEqual([7]);
        expect(w.find('[data-open-meta]').attributes('href')).toBe(source.manager_url);
        // A secondary inline action is an icon (F7): the label is its accessible name and tooltip; opens in a new tab.
        expect(w.find('[data-open-meta]').attributes('aria-label')).toBe('افتح في ميتا');
        expect(w.find('[data-open-meta]').attributes('target')).toBe('_blank');
    });

    it('is a plain chip without ads access, and says direct without an ad', () => {
        const w = mount(OrderSourceChip, { props: { source, canOpenAds: false } });
        expect(w.find('[data-open-ad]').exists()).toBe(false);
        expect(w.find('[data-open-meta]').exists()).toBe(false);
        expect(mount(OrderSourceChip, { props: { source: null } }).text()).toContain('مباشر');
    });
});

const row = (o: Partial<OrdersByAdRow>): OrdersByAdRow => ({
    ad_id: 1,
    ad: 'A',
    thumbnail_url: null,
    external_id: '11',
    platform: 'meta',
    campaign_id: 1,
    campaign: 'خريف',
    ad_set_id: 1,
    ad_set: 'نساء',
    orders: 1,
    revenue: 100,
    units: 1,
    ...o,
});

describe('OrdersAdsTab', () => {
    it('groups platform → campaign → ad set → ad, links the orders count and shows the direct row', async () => {
        const rows = [
            row({ ad_id: 1, ad: 'اسدال', orders: 4, revenue: 1000, units: 4 }),
            row({ ad_id: 2, ad: 'طرح', orders: 1, ad_set_id: 2, ad_set: 'رجالي' }),
            row({ ad_id: 3, ad: 'تيك', platform: 'tiktok', campaign_id: 9, campaign: 'تيك توك', external_id: '33' }),
            row({ ad_id: null, ad: null, platform: 'direct', campaign_id: null, campaign: null, ad_set_id: null, ad_set: null, orders: 2 }),
        ];
        const w = mount(OrdersAdsTab, { props: { rows, canOpenAds: true, query: 'from=2026-10-01&to=2026-10-06' } });
        expect(w.findAll('[data-platform]').map((s) => s.attributes('data-platform'))).toEqual(['meta', 'tiktok']);
        expect(w.find('[data-platform="meta"] h3').text()).toContain('٥'); // 4 + 1 orders, Arabic digits
        expect(w.findAll('[data-ad-orders]')[0].attributes('href')).toBe('/orders/ads/1?from=2026-10-01&to=2026-10-06');
        expect(w.findAll('[data-open-meta]')).toHaveLength(2); // Meta ads only
        await w.findAll('[data-open-ad]')[0].trigger('click');
        expect(w.emitted('open-ad')?.[0]).toEqual([1]);
        expect(w.find('[data-direct]').text()).toContain('مباشر');
        // 390 px: the figures drop under the name, full width, each cell truncating; sm+: a fixed end column.
        const figures = w.find('[data-ad-row] .grid').classes();
        expect(figures).toEqual(expect.arrayContaining(['w-full', 'sm:w-64', '[&>*]:truncate']));
    });
});

describe('OrdersAnalyticsTab', () => {
    it('shows the totals, top products with images and the rest bucket', () => {
        const data: OrdersAnalytics = {
            totals: { orders: 5, real_orders: 4, revenue: 1400, aov: 350, customers: 3, new_customers: 1, repeat_customers: 2, units: 7 },
            governorates: [
                { key: 'C', label: 'القاهرة', orders: 2, revenue: 800 },
                { key: '_rest', label: null, orders: 3, revenue: 600, count: 4 },
            ],
            districts: [{ key: null, label: null, orders: 5, revenue: 1400 }],
            frequency: { one: 2, two: 1, three_plus: 0, customers: [{ id: 3, name: 'منى', phone: '010', orders: 2 }] },
            products: [{ title: 'طرحة', image_url: 'https://cdn.test/t.jpg', units: 4, revenue: 120 }],
            statuses: [{ key: 'delivered', orders: 1 }],
            days: [{ date: '2026-10-05', orders: 4, revenue: 1000 }],
        };
        const w = mount(OrdersAnalyticsTab, { props: { data } });
        const text = w.text();
        expect(text).toContain('الباقي');
        expect(text).toContain('مش محدد');
        expect(text).toContain('منى');
        expect(w.find('[data-top-products] img').attributes('src')).toBe('https://cdn.test/t.jpg');
    });

    it('shows an empty state without orders', () => {
        const empty = {
            totals: { orders: 0, real_orders: 0, revenue: 0, aov: 0, customers: 0, units: 0 },
            governorates: [],
            districts: [],
            frequency: { one: 0, two: 0, three_plus: 0, customers: [] },
            products: [],
            statuses: [],
            days: [],
        };
        expect(mount(OrdersAnalyticsTab, { props: { data: empty } }).text()).toContain('مفيش أوردرات');
    });
});
