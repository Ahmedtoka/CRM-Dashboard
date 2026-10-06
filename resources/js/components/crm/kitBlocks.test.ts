import InlineError from '@/components/crm/InlineError.vue';
import ProgressBar from '@/components/crm/ProgressBar.vue';
import SkeletonList from '@/components/crm/SkeletonList.vue';
import { translate } from '@/i18n';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('SkeletonList', () => {
    it.each([
        ['table', 8],
        ['cards', 3],
        ['tiles', 4],
    ] as const)('%s renders %i placeholders by default', (variant, n) => {
        const w = mount(SkeletonList, { props: { variant } });
        expect(w.findAll('[data-skeleton-item]')).toHaveLength(n);
        expect(w.attributes('role')).toBe('status');
    });

    it('honours count', () => {
        expect(mount(SkeletonList, { props: { variant: 'cards', count: 5 } }).findAll('[data-skeleton-item]')).toHaveLength(5);
    });
});

describe('ProgressBar', () => {
    it('shows n of m and the matching width', () => {
        const w = mount(ProgressBar, { props: { value: 3, max: 10, label: 'Sync' } });
        expect(w.text()).toContain(translate('ar', 'ui.progress', { n: 3, m: 10 }));
        expect((w.find('[role="progressbar"] > div').element as HTMLElement).style.width).toBe('30%');
        expect(w.find('[role="progressbar"]').attributes('aria-valuemax')).toBe('10');
    });

    it('clamps and survives max 0', () => {
        expect((mount(ProgressBar, { props: { value: 12, max: 10 } }).find('[role="progressbar"] > div').element as HTMLElement).style.width).toBe('100%');
        expect((mount(ProgressBar, { props: { value: 0, max: 0 } }).find('[role="progressbar"] > div').element as HTMLElement).style.width).toBe('0%');
    });
});

describe('InlineError', () => {
    it('emits retry', async () => {
        const w = mount(InlineError);
        expect(w.text()).toContain(translate('ar', 'ui.load_failed'));
        await w.find('button').trigger('click');
        expect(w.emitted('retry')).toHaveLength(1);
    });
});
