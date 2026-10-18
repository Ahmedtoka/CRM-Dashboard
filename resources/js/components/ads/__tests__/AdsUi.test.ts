import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it } from 'vitest';

import PlatformChip from '@/components/ads/PlatformChip.vue';
import StageBoxes from '@/components/ads/ui/StageBoxes.vue';
import { setCurrentLocale } from '@/composables/useI18n';

beforeAll(() => setCurrentLocale('en'));

describe('PlatformChip', () => {
    it('gives TikTok the same tile and name as Meta', () => {
        for (const p of ['meta', 'tiktok', 'google']) {
            const w = mount(PlatformChip, { props: { platform: p } });
            expect(w.attributes('data-platform')).toBe(p);
            expect(w.find('svg').exists()).toBe(true);
        }
        expect(mount(PlatformChip, { props: { platform: 'tiktok' } }).text()).toContain('TikTok');
    });

    it('keeps the name for screen readers when only the tile shows', () => {
        const w = mount(PlatformChip, { props: { platform: 'tiktok', iconOnly: true } });
        expect(w.find('.sr-only').text()).toBe('TikTok');
        expect(w.attributes('title')).toBe('TikTok');
    });
});

describe('StageBoxes', () => {
    const boxes = [
        { key: 'review', label: 'Waiting for me', count: 3, mine: true },
        { key: 'live', label: 'Live', count: 11 },
    ];

    it('marks the chosen stage pressed and emits the one clicked', async () => {
        const w = mount(StageBoxes, { props: { boxes, current: 'review', label: 'Stages' } });
        expect(w.find('[data-box="review"]').attributes('aria-pressed')).toBe('true');
        expect(w.find('[data-box="live"]').attributes('aria-pressed')).toBe('false');
        await w.find('[data-box="live"]').trigger('click');
        expect(w.emitted('select')?.[0]).toEqual(['live']);
    });
});
