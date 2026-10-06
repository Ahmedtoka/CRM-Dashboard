import ChecksPanel from '@/components/ads/launch/ChecksPanel.vue';
import type { CheckRow } from '@/types/ads';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const row = (key: string, level: CheckRow['level'], ar: string): CheckRow => ({ key, level, message_ar: ar, message_en: key, details: {} });

describe('ChecksPanel', () => {
    it('lists blocking first, then warnings, and folds the passed ones', async () => {
        const w = mount(ChecksPanel, {
            props: { checks: [row('stock', 'pass', 'متوفر'), row('caption_price', 'block', 'السعر غلط'), row('naming', 'warn', 'الاسم')] },
        });

        const text = w.text();
        expect(text.indexOf('السعر غلط')).toBeLessThan(text.indexOf('الاسم'));
        expect(text).not.toContain('متوفر');
        await w.find('button[aria-expanded]').trigger('click');
        expect(w.text()).toContain('متوفر');
    });

    it('lets the approver tick a warning as seen', async () => {
        const w = mount(ChecksPanel, { props: { checks: [row('landing_http', 'warn', 'الصفحة ما فتحتش')], ackable: true, acked: [] } });

        await w.find('input[type="checkbox"]').setValue(true);
        expect(w.emitted('update:acked')?.[0]).toEqual([['landing_http']]);
    });

    it('says all is fine when nothing blocks or warns', () => {
        const w = mount(ChecksPanel, { props: { checks: [row('stock', 'pass', 'متوفر')] } });

        expect(w.text()).toContain('كل الفحوصات تمام');
    });
});
