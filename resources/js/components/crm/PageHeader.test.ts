import FreshnessChip from '@/components/crm/FreshnessChip.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import { translate } from '@/i18n';
import { formatClock } from '@/lib/format';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('FreshnessChip', () => {
    it('says until when the data is', () => {
        const at = new Date().toISOString();
        const w = mount(FreshnessChip, { props: { at } });
        expect(w.text()).toBe(translate('ar', 'ui.freshness', { time: formatClock(at, 'ar') }));
    });

    it('renders nothing without a timestamp', () => {
        expect(mount(FreshnessChip, { props: { at: null } }).html()).toBe('<!--v-if-->');
    });
});

describe('PageHeader', () => {
    it('renders title, parent breadcrumbs, freshness and actions', () => {
        const w = mount(PageHeader, {
            props: { title: 'أوردر 1001', breadcrumbs: [{ label: 'الأوردرات', href: '/orders' }, { label: 'أوردر 1001' }], freshness: new Date().toISOString() },
            slots: { default: '<button>action</button>' },
        });
        expect(w.find('h1').text()).toBe('أوردر 1001');
        const crumbs = w.find('nav').findAll('a');
        expect(crumbs).toHaveLength(1);
        expect(crumbs[0].attributes('href')).toBe('/orders');
        expect(w.text()).toContain(translate('ar', 'ui.freshness', { time: '' }).trim().split(' ')[0]);
        expect(w.text()).toContain('action');
    });

    it('publishes --page-header-h only when sticky', () => {
        const plain = mount(PageHeader, { props: { title: 'x' }, attachTo: document.body });
        expect(document.documentElement.style.getPropertyValue('--page-header-h')).toBe('');
        plain.unmount();
        const sticky = mount(PageHeader, { props: { title: 'x', sticky: true }, attachTo: document.body });
        expect(document.documentElement.style.getPropertyValue('--page-header-h')).toMatch(/px$/);
        sticky.unmount();
        expect(document.documentElement.style.getPropertyValue('--page-header-h')).toBe('');
    });
});
