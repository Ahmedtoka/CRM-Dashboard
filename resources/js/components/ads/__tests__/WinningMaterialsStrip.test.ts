import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it } from 'vitest';

import WinningMaterialsStrip from '@/components/ads/WinningMaterialsStrip.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import type { WinningMaterial } from '@/types/adsLibrary';

// ar-EG prints Arabic-Indic digits; assert on Latin digits in en.
beforeAll(() => setCurrentLocale('en'));

const winner = (over: Partial<WinningMaterial> = {}): WinningMaterial => ({
    id: 21,
    title: 'Eid reel',
    product_title: 'Black abaya',
    thumbnail: '/ads/materials/files/5/thumb',
    status: 'live',
    performance: { spend_tax: 3420, purchase_value: 12000, roas: 4, real_orders: 7, winner_tier: 'winner' },
    ads: [{ id: 1, name: 'Eid reel ad', platform: 'meta' }],
    can_reuse: true,
    ...over,
});

describe('WinningMaterialsStrip', () => {
    it('renders a card per material with its title, product, tier and results line', () => {
        const w = mount(WinningMaterialsStrip, { props: { winners: [winner(), winner({ id: 22, title: 'Second reel', performance: { spend_tax: 1000, purchase_value: 2600, roas: 2.6, real_orders: 0, winner_tier: 'promising' } })] } });
        const cards = w.findAll('[data-test="winner-card"]');
        expect(cards).toHaveLength(2);
        expect(cards[0].text()).toContain('Eid reel');
        expect(cards[0].text()).toContain('Black abaya');
        expect(cards[0].text()).toContain('Winner');
        expect(cards[0].text()).toContain('3,420');
        expect(cards[0].text()).toContain('7');
        expect(cards[0].text()).toContain('4.00×');
        expect(cards[0].find('img').attributes('src')).toBe('/ads/materials/files/5/thumb');
        expect(cards[1].text()).toContain('Promising');
    });

    it('emits reuse with the material id from the primary button', async () => {
        const w = mount(WinningMaterialsStrip, { props: { winners: [winner()] } });
        await w.get('[data-test="winner-reuse"]').trigger('click');
        expect(w.emitted('reuse')?.[0]).toEqual([21]);
    });

    it('hides the reuse button when the viewer cannot reuse', () => {
        const w = mount(WinningMaterialsStrip, { props: { winners: [winner({ can_reuse: false })] } });
        expect(w.find('[data-test="winner-reuse"]').exists()).toBe(false);
        expect(w.find('[data-test="winner-card"]').exists()).toBe(true);
    });
});
