import EmptyState from '@/components/crm/EmptyState.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('vitest harness', () => {
    it('mounts a CRM component with the @ alias', () => {
        const wrapper = mount(EmptyState, { props: { title: 'لا توجد بيانات' } });

        expect(wrapper.text()).toContain('لا توجد بيانات');
    });
});
