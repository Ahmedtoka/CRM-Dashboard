import RatingsSection from '@/components/crm/reports/RatingsSection.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

const Link = { props: ['href'], template: '<a :href="href"><slot /></a>' };
const base = {
    stars: 'all' as const,
    summary: { count: 2, avg: 3, low: 1 },
    by_agent_day: [{ user: { id: 7, name: 'Mona', color: null }, date: '2026-10-06', count: 2, avg: 3, low: 1 }],
    list: [{ entry_id: 9, conversation_id: 41, stars: 1, reviewed_at: '2026-10-06T08:00:00+00:00', user: { id: 7, name: 'Mona' }, customer: 'Sara' }],
};

describe('RatingsSection', () => {
    beforeEach(() => setCurrentLocale('en'));

    it('shows the summary with one decimal and links each answer to its chat', () => {
        const w = mount(RatingsSection, { props: { ratings: base, range: { from: '2026-10-06', to: '2026-10-06' } }, global: { stubs: { Link } } });
        expect(w.attributes('id')).toBe('ratings');
        expect(w.text()).toContain('3.0');
        expect(w.find('a[href="/inbox?c=41"]').exists()).toBe(true);
        expect(w.text()).toContain('Mona');
    });

    it('says so when nobody rated', () => {
        const w = mount(RatingsSection, {
            props: {
                ratings: { ...base, summary: { count: 0, avg: null, low: 0 }, by_agent_day: [], list: [] },
                range: { from: '2026-10-06', to: '2026-10-06' },
            },
            global: { stubs: { Link } },
        });
        expect(w.text()).toContain('No ratings in this period');
    });
});
