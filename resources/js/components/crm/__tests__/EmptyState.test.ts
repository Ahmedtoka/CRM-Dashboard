import EmptyState from '@/components/crm/EmptyState.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { mount } from '@vue/test-utils';
import { Inbox } from 'lucide-vue-next';
import { describe, expect, it } from 'vitest';

describe('EmptyState', () => {
    it('says what happened and offers one action', () => {
        const w = mount(EmptyState, {
            props: { icon: Inbox, title: 'مفيش أوردرات في الفترة دي', body: 'وسّعي التاريخ أو امسحي الفلاتر' },
            slots: { action: '<button>امسحي الفلاتر</button>' },
        });

        expect(w.attributes('data-empty-variant')).toBe('empty');
        expect(w.attributes('role')).toBeUndefined();
        expect(w.text()).toContain('وسّعي التاريخ أو امسحي الفلاتر');
        expect(w.find('button').text()).toBe('امسحي الفلاتر');
    });

    it('announces an error with its own icon even without one passed', () => {
        const w = mount(EmptyState, { props: { title: 'شوبيفاي مش بيرد', body: 'جربي تاني بعد دقيقة', variant: 'error' } });

        expect(w.attributes('role')).toBe('alert');
        expect(w.find('svg').exists()).toBe(true);
        expect(w.html()).toContain('bg-danger-soft');
    });
});

describe('StatusChip', () => {
    it('maps the old tone names onto the new ones', () => {
        expect(mount(StatusChip, { props: { label: 'اتسلّم', tone: 'positive' } }).classes()).toContain('bg-ok-soft');
        expect(mount(StatusChip, { props: { label: 'متأخر', tone: 'negative' } }).classes()).toContain('text-danger');
        expect(mount(StatusChip, { props: { label: 'ملاحظة', tone: 'note' } }).classes()).toContain('bg-note-soft');
    });

    it('has a small size for the 72px inbox row', () => {
        expect(mount(StatusChip, { props: { label: 'مستنية', size: 'sm' } }).classes()).toContain('h-5');
    });
});
