import { Button } from '@/components/ui/button';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('Button loading', () => {
    it('shows a spinner, disables, and keeps the label for width', () => {
        const w = mount(Button, { props: { loading: true }, slots: { default: 'حفظ' } });
        expect(w.attributes('disabled')).toBeDefined();
        expect(w.attributes('aria-busy')).toBe('true');
        expect(w.find('svg.animate-spin').exists()).toBe(true);
        const label = w.find('[data-button-label]');
        expect(label.text()).toBe('حفظ');
        expect(label.classes()).toContain('invisible');
    });

    it('is a normal button when idle', () => {
        const w = mount(Button, { slots: { default: 'حفظ' } });
        expect(w.attributes('disabled')).toBeUndefined();
        expect(w.find('svg.animate-spin').exists()).toBe(false);
        expect(w.find('[data-button-label]').classes()).not.toContain('invisible');
    });

    it('loading wins over disabled=false', () => {
        const w = mount(Button, { props: { loading: true, disabled: false }, slots: { default: 'x' } });
        expect(w.attributes('disabled')).toBeDefined();
    });

    it('marks a link-like button busy without a disabled attribute', () => {
        const w = mount(Button, { props: { as: 'a', loading: true }, slots: { default: 'x' } });
        expect(w.attributes('disabled')).toBeUndefined();
        expect(w.attributes('aria-disabled')).toBe('true');
    });
});
