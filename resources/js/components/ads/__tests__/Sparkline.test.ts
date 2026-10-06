import HealthBadge from '@/components/ads/HealthBadge.vue';
import Sparkline from '@/components/ads/Sparkline.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const pts = (spend: number[], roas: (number | null)[]) => spend.map((s, i) => ({ date: `2026-10-${String(i + 1).padStart(2, '0')}`, spend: s, roas: roas[i] ?? null }));

describe('Sparkline', () => {
    it('draws one bar per day and a ROAS line, with a text label', () => {
        const w = mount(Sparkline, { props: { points: pts([10, 5, 30], [1, 2, 3]) } });
        expect(w.findAll('rect')).toHaveLength(3);
        expect(w.findAll('polyline').length).toBeGreaterThan(0);
        expect(w.find('svg').attributes('role')).toBe('img');
        expect(w.find('svg').attributes('aria-label')).toBeTruthy();
    });

    it('says there was no spend instead of an empty chart', () => {
        const w = mount(Sparkline, { props: { points: pts([0, 0], [null, null]) } });
        expect(w.find('svg').exists()).toBe(false);
        expect(w.text().length).toBeGreaterThan(0);
    });
});

describe('HealthBadge', () => {
    it('renders the badge text (never colour only)', () => {
        expect(mount(HealthBadge, { props: { kind: 'losing' } }).text()).toBe('بيخسر');
    });
});
