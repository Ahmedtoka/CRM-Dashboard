import AdSourceChip from '@/components/crm/AdSourceChip.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

describe('AdSourceChip', () => {
    it('shows the ad name with its thumbnail and campaign', () => {
        const w = mount(AdSourceChip, { props: { source: { name: 'اسدال كتان', thumbnail_url: 'https://cdn.test/a.jpg', campaign: 'خريف 2026' } } });
        expect(w.text()).toContain('اسدال كتان');
        expect(w.find('img').attributes('src')).toBe('https://cdn.test/a.jpg');
        expect(w.attributes('aria-label')).toContain('خريف 2026');
    });

    it('says direct when there is no ad', () => {
        expect(mount(AdSourceChip, { props: { source: null } }).text()).toBe('مباشر');
    });
});
