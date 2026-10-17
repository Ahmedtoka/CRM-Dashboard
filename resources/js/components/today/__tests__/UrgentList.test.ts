import Ledger from '@/components/today/Ledger.vue';
import UrgentList from '@/components/today/UrgentList.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));

describe('UrgentList', () => {
    it('links each row to where it gets solved, with its words and tone', () => {
        const w = mount(UrgentList, {
            props: {
                title: 'محتاج تدخلك',
                rows: [
                    { key: 'lounge', label: '٤ عميلات مستنيين في الصالة', detail: 'أطول انتظار ١٧ دقيقة', href: '/board', tone: 'danger' },
                    { key: 'launches', label: '٣ إطلاقات مستنية موافقتك', href: '/ads/approvals', tone: 'brand' },
                ],
            },
        });

        const rows = w.findAll('a');
        expect(rows.map((a) => a.attributes('href'))).toEqual(['/board', '/ads/approvals']);
        expect(rows[0].text()).toContain('٤ عميلات مستنيين في الصالة');
        expect(rows[0].text()).toContain('أطول انتظار ١٧ دقيقة');
        expect(rows[0].attributes('data-tone')).toBe('danger');
    });

    it('says so when nothing needs attention', () => {
        const w = mount(UrgentList, { props: { title: 'محتاج تدخلك', rows: [], empty: 'مفيش حاجة مستعجلة' } });

        expect(w.text()).toContain('مفيش حاجة مستعجلة');
        expect(w.findAll('a')).toHaveLength(0);
    });
});

describe('Ledger', () => {
    it('shows each figure with its unit and note in one panel, linking when it has a page', () => {
        const w = mount(Ledger, {
            props: {
                label: 'أرقام النهارده',
                figures: [
                    { key: 'orders', label: 'أوردرات', value: '١٤٢', note: 'أمس ١٢٨', tone: 'up', href: '/orders' },
                    { key: 'sales', label: 'مبيعات', value: '١٨٤٬٢٠٠', unit: 'ج.م' },
                ],
            },
        });

        expect(w.find('section').attributes('aria-label')).toBe('أرقام النهارده');
        expect(w.find('[data-ledger="orders"]').attributes('href')).toBe('/orders');
        expect(w.find('[data-ledger="orders"]').text()).toContain('أمس ١٢٨');
        expect(w.find('[data-ledger="sales"]').element.tagName).toBe('DIV');
        expect(w.find('[data-ledger="sales"]').text()).toContain('ج.م');
    });
});
