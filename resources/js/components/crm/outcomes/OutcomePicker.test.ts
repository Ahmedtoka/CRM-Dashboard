import OutcomePicker from '@/components/crm/outcomes/OutcomePicker.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

type Picker = { handleKey: (k: string) => boolean };

describe('OutcomePicker', () => {
    it('picks with a click and with a digit key', async () => {
        const w = mount(OutcomePicker, { props: { modelValue: null, note: '' } });
        await w.find('[data-outcome="size_out"]').trigger('click');
        expect(w.emitted('update:modelValue')?.[0]).toEqual(['size_out']);

        expect((w.vm as unknown as Picker).handleKey('3')).toBe(true);
        expect(w.emitted('update:modelValue')?.[1]).toEqual(['shipping']);
        expect((w.vm as unknown as Picker).handleKey('x')).toBe(false);
    });

    it('locks to an automatic ordered and ignores keys', () => {
        const w = mount(OutcomePicker, { props: { modelValue: null, note: '', auto: 'ordered' } });
        expect(w.find('[data-outcome-locked]').text()).toContain('اتعمل أوردر');
        expect(w.findAll('[data-outcome]')).toHaveLength(0);
        expect((w.vm as unknown as Picker).handleKey('1')).toBe(false);
    });

    it('asks for a note when other is picked', async () => {
        const w = mount(OutcomePicker, { props: { modelValue: 'other', note: '' } });
        const input = w.find('input[data-outcome-note]');
        expect(input.exists()).toBe(true);
        await input.setValue('تقسيط');
        expect(w.emitted('update:note')?.[0]).toEqual(['تقسيط']);
    });

    it('marks an automatic service as preselected but lets her change it', () => {
        const w = mount(OutcomePicker, { props: { modelValue: null, note: '', auto: 'service' } });
        expect(w.find('[data-outcome="service"]').attributes('aria-checked')).toBe('true');
        expect(w.find('[data-outcome="price"]').exists()).toBe(true);
    });
});
