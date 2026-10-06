import IconAction from '@/components/crm/IconAction.vue';
import { mount } from '@vue/test-utils';
import { Pencil } from 'lucide-vue-next';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href" v-bind="$attrs"><slot /></a>' },
}));

describe('IconAction', () => {
    it('is an icon button named by its label and emits click', async () => {
        const w = mount(IconAction, { props: { label: 'Edit Ahmed', icon: Pencil } });
        const button = w.get('button');
        expect(button.attributes('aria-label')).toBe('Edit Ahmed');
        expect(button.attributes('type')).toBe('button');
        expect(button.find('svg').exists()).toBe(true);
        expect(button.text()).toBe('');
        await button.trigger('click');
        expect(w.emitted('click')).toHaveLength(1);
    });

    it('shows the label as a tooltip on hover', async () => {
        const w = mount(IconAction, { props: { label: 'Sync now', icon: Pencil }, attachTo: document.body });
        await w.get('button').trigger('focus');
        await new Promise((r) => setTimeout(r, 20));
        expect(document.body.textContent).toContain('Sync now');
        w.unmount();
    });

    it('spins and is disabled while loading, and never emits', async () => {
        const w = mount(IconAction, { props: { label: 'Sync', icon: Pencil, loading: true } });
        const button = w.get('button');
        expect(button.attributes('disabled')).toBeDefined();
        expect(button.attributes('aria-busy')).toBe('true');
        expect(button.find('.animate-spin').exists()).toBe(true);
        await button.trigger('click');
        expect(w.emitted('click')).toBeUndefined();
    });

    it('keeps the tooltip on a disabled action so it can say why (focusable wrapper)', async () => {
        const w = mount(IconAction, { props: { label: 'Sync stopped for this account', icon: Pencil, disabled: true }, attachTo: document.body });
        const wrap = w.get('[data-icon-action-wrap]');
        expect(wrap.attributes('tabindex')).toBe('0');
        expect(wrap.get('button').attributes('disabled')).toBeDefined();
        await wrap.trigger('focus');
        await new Promise((r) => setTimeout(r, 20));
        expect(document.body.textContent).toContain('Sync stopped for this account');
        w.unmount();
    });

    it('renders a link when given an href', () => {
        const w = mount(IconAction, { props: { label: 'Open', icon: Pencil, href: '/ads/sync?account=3' } });
        const a = w.get('a');
        expect(a.attributes('href')).toBe('/ads/sync?account=3');
        expect(a.attributes('aria-label')).toBe('Open');
    });

    it('tones the destructive variant', () => {
        const w = mount(IconAction, { props: { label: 'Delete', icon: Pencil, variant: 'destructive' } });
        expect(w.get('button').classes().join(' ')).toContain('text-destructive');
    });
});
