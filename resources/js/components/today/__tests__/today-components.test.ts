import DayToggle from '@/components/today/DayToggle.vue';
import TeamLine from '@/components/today/TeamLine.vue';
import TodayCard from '@/components/today/TodayCard.vue';
import UrgentStrip from '@/components/today/UrgentStrip.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

const Link = { props: ['href'], template: '<a :href="href"><slot /></a>' };
const global = { stubs: { Link } };

describe('Today components', () => {
    beforeEach(() => setCurrentLocale('en'));

    it('TodayCard makes every number a link and marks bad tones', () => {
        const w = mount(TodayCard, {
            props: { title: 'Orders', rows: [{ key: 'failed', label: 'Failed', value: '1', href: '/orders?status=failed', tone: 'bad' }] },
            global,
        });
        const a = w.find('a[href="/orders?status=failed"]');
        expect(a.exists()).toBe(true);
        expect(a.text()).toContain('1');
        expect(a.classes().join(' ')).toContain('text-destructive');
    });

    it('TodayCard shows its empty line when there are no rows', () => {
        const w = mount(TodayCard, { props: { title: 'Why', rows: [], empty: 'No outcomes' }, global });
        expect(w.text()).toContain('No outcomes');
    });

    it('UrgentStrip says nothing is urgent, or lists the links', () => {
        expect(mount(UrgentStrip, { props: { items: [] }, global }).text()).toContain('Nothing urgent');
        const w = mount(UrgentStrip, { props: { items: [{ key: 'cases_overdue', count: 1, tone: 'danger', href: '/cases?overdue=1' }] }, global });
        expect(w.find('a[href="/cases?overdue=1"]').text()).toContain('1 cases past SLA');
    });

    it('DayToggle links both days and marks the current one', () => {
        const w = mount(DayToggle, { props: { mode: 'yesterday' }, global });
        expect(w.find('a[href="/today"]').attributes('aria-current')).toBeUndefined();
        expect(w.find('a[href="/today?day=yesterday"]').attributes('aria-current')).toBe('page');
    });

    it('TeamLine links each agent and the room', () => {
        const rows = [
            {
                user: { id: 7, name: 'Mona', color: null },
                online: true,
                desk_status: 'busy',
                windows_closed: 3,
                orders: 1,
                rating: { count: 1, avg: 4, low: 0 },
                href: '/reports/users/7?from=d&to=d',
            },
        ];
        const w = mount(TeamLine, { props: { rows, mode: 'today', canSeeBoard: true }, global });
        expect(w.find('a[href="/reports/users/7?from=d&to=d"]').text()).toContain('Mona');
        expect(w.find('a[href="/board"]').text()).toContain('The room');
    });
});
