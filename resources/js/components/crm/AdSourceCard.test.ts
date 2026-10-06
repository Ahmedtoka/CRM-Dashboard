import AdSourceCard from '@/components/crm/AdSourceCard.vue';
import type { AdContext } from '@/types/crm';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const ad: AdContext = {
    ad_id: 12,
    external_id: '900',
    name: 'عباية سادة',
    thumbnail_url: 'https://cdn.test/b.jpg',
    campaign: 'خريف 2026',
    adset: 'بنات 18-30',
    referred_at: '2026-10-08T09:00:00+00:00',
    can_open: true,
    history: [
        { ad_id: 12, external_id: '900', name: 'عباية سادة', thumbnail_url: null, referred_at: '2026-10-08T09:00:00+00:00' },
        { ad_id: 11, external_id: '800', name: 'اسدال كتان', thumbnail_url: null, referred_at: '2026-10-07T09:00:00+00:00' },
    ],
};

describe('AdSourceCard', () => {
    it('shows the thumbnail, name, campaign and ad set, and links to the ad drawer', () => {
        const w = mount(AdSourceCard, { props: { ad } });
        expect(w.find('img').attributes('src')).toBe('https://cdn.test/b.jpg');
        expect(w.text()).toContain('عباية سادة');
        expect(w.text()).toContain('خريف 2026');
        expect(w.text()).toContain('بنات 18-30');
        expect(w.find('a[data-ad-open]').attributes('href')).toBe('/ads/explorer?ad=12');
    });

    it('lists earlier ads behind «+n» and hides the link for agents', async () => {
        const w = mount(AdSourceCard, { props: { ad: { ...ad, can_open: false } } });
        expect(w.find('a[data-ad-open]').exists()).toBe(false);
        expect(w.text()).not.toContain('اسدال كتان');
        await w.find('button[data-ad-history]').trigger('click');
        expect(w.text()).toContain('اسدال كتان');
    });
});
