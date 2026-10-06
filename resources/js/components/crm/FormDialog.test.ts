import FormDialog from '@/components/crm/FormDialog.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('FormDialog submit', () => {
    it('uses the Button loading state while busy', async () => {
        const w = mount(FormDialog, { props: { open: true, title: 'x', busy: true }, attachTo: document.body });
        await flushPromises();
        const submit = document.body.querySelector('button[type="submit"]') as HTMLButtonElement;
        expect(submit.disabled).toBe(true);
        expect(submit.getAttribute('aria-busy')).toBe('true');
        expect(submit.querySelector('[data-button-label]')?.classList.contains('invisible')).toBe(true);
        w.unmount();
    });
});
