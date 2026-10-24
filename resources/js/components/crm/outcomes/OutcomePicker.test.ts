import OutcomePicker from '@/components/crm/outcomes/OutcomePicker.vue';
import { AGENT_OUTCOMES, outcomeForKey } from '@/lib/outcomes';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

type Picker = { handleKey: (k: string) => boolean };

describe('OutcomePicker', () => {
    it('shows every outcome in three labelled groups: a sale, a service chat, the rest', () => {
        const w = mount(OutcomePicker, { props: { modelValue: null, note: '' } });
        const groups = w.findAll('[data-outcome-group]');
        expect(groups.map((g) => g.attributes('data-outcome-group'))).toEqual(['sale', 'service', 'rest']);
        expect(groups.map((g) => g.findAll('[data-outcome]').length)).toEqual([5, 5, 3]);
        expect(w.findAll('[data-outcome]').map((b) => b.attributes('data-outcome'))).toEqual(AGENT_OUTCOMES);
    });

    it('picks with a click and with a digit key (1-9 in sheet order, Arabic digits too)', async () => {
        const w = mount(OutcomePicker, { props: { modelValue: null, note: '' } });
        await w.find('[data-outcome="size_out"]').trigger('click');
        expect(w.emitted('update:modelValue')?.[0]).toEqual(['size_out']);

        expect((w.vm as unknown as Picker).handleKey('4')).toBe(true);
        expect(w.emitted('update:modelValue')?.[1]).toEqual(['shipping']);
        expect((w.vm as unknown as Picker).handleKey('٧')).toBe(true);
        expect(w.emitted('update:modelValue')?.[2]).toEqual(['return_case']);
        expect((w.vm as unknown as Picker).handleKey('x')).toBe(false);
        expect(outcomeForKey('0')).toBeNull();
    });

    it('locks nothing: an order in the chat is only a suggestion she can change', async () => {
        const w = mount(OutcomePicker, { props: { modelValue: 'ordered', note: '', auto: 'ordered' } });
        expect(w.find('[data-outcome-locked]').exists()).toBe(false);
        expect(w.find('[data-outcome="ordered"]').attributes('aria-checked')).toBe('true');
        expect(w.find('[data-outcome-auto]').exists()).toBe(true);
        await w.find('[data-outcome="return_case"]').trigger('click');
        expect(w.emitted('update:modelValue')?.[0]).toEqual(['return_case']);
    });

    it('shows a disabled loading row while the outcome state is unknown and ignores keys', () => {
        const w = mount(OutcomePicker, { props: { modelValue: null, note: '', loading: true } });
        expect(w.find('[data-outcome-loading]').exists()).toBe(true);
        expect(w.findAll('[data-outcome]')).toHaveLength(0);
        expect((w.vm as unknown as Picker).handleKey('1')).toBe(false);
    });

    it('asks for a note when «حاجة تانية» is picked', async () => {
        const w = mount(OutcomePicker, { props: { modelValue: 'other', note: '' } });
        const input = w.find('input[data-outcome-note]');
        expect(input.exists()).toBe(true);
        await input.setValue('تقسيط');
        expect(w.emitted('update:note')?.[0]).toEqual(['تقسيط']);
    });
});
