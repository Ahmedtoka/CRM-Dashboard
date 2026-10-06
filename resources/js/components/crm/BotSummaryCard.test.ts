import BotSummaryCard from '@/components/crm/BotSummaryCard.vue';
import type { HandoverDigest } from '@/types/crm';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const digest: HandoverDigest = {
    reason: 'سؤال عن المقاس',
    category: 'المقاسات',
    topic: null,
    order_number: '1043',
    lines: [
        { label: 'العنوان', value: 'الهرم' },
        { label: null, value: 'عايزة توصيل بكرة' },
    ],
    products: ['اسدال كتان'],
    sizes: ['L'],
    colors: ['بيج'],
    governorate: 'الجيزة',
    last_message: 'هو الشحن لأكتوبر بكام؟',
};

describe('BotSummaryCard', () => {
    it('renders every line as a visible definition list, no title tooltip', () => {
        const w = mount(BotSummaryCard, { props: { digest } });
        const text = w.text();
        for (const value of ['سؤال عن المقاس', 'المقاسات', '1043', 'اسدال كتان', 'L', 'بيج', 'الجيزة', 'الهرم', 'عايزة توصيل بكرة', 'هو الشحن لأكتوبر بكام؟']) {
            expect(text).toContain(value);
        }
        expect(w.findAll('dt').length).toBeGreaterThanOrEqual(8);
        expect(w.find('[title]').exists()).toBe(false);
    });

    it('skips empty fields', () => {
        const w = mount(BotSummaryCard, { props: { digest: { ...digest, sizes: [], colors: [], governorate: null, lines: [], order_number: null } } });
        expect(w.text()).not.toContain('المحافظة');
    });
});
