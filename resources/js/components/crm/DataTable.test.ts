import DataTable from '@/components/crm/DataTable.vue';
import { loose } from '@/test/loose';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

const columns = [
    { key: 'name', label: 'Name', primary: true },
    { key: 'spend', label: 'Spend', sortable: true, numeric: true },
];
const rows = [
    { id: 1, name: 'A', spend: 10 },
    { id: 2, name: 'B', spend: 20 },
];
const Table = loose(DataTable);

describe('DataTable', () => {
    beforeEach(() => localStorage.clear());

    it('keeps the header sticky inside its own scroll box by default', () => {
        const w = mount(Table, { props: { columns, rows } });
        expect(w.find('thead').classes()).toContain('crm-sticky-head');
        expect(w.find('[data-table-box]').classes()).toContain('table-scroll-box');
    });

    it('can turn the sticky box off and stick the first column', () => {
        const w = mount(Table, { props: { columns, rows, stickyHeader: false, stickyFirstColumn: true } });
        expect(w.find('thead').classes()).not.toContain('crm-sticky-head');
        expect(w.find('[data-table-box]').classes()).toContain('overflow-x-auto');
        expect(w.find('tbody tr td').classes()).toContain('crm-sticky-first');
    });

    it('emits -key first, then key, and marks aria-sort', async () => {
        const w = mount(Table, { props: { columns, rows, sort: null } });
        expect(w.findAll('th')[0].find('button').exists()).toBe(false);
        await w.findAll('th')[1].find('button').trigger('click');
        expect(w.emitted('update:sort')?.[0]).toEqual(['-spend']);
        await w.setProps({ sort: '-spend' });
        expect(w.findAll('th')[1].attributes('aria-sort')).toBe('descending');
        await w.findAll('th')[1].find('button').trigger('click');
        expect(w.emitted('update:sort')?.[1]).toEqual(['spend']);
    });

    it('right-aligns numeric cells with tabular digits', () => {
        const cell = mount(Table, { props: { columns, rows } }).findAll('tbody tr')[0].findAll('td')[1];
        expect(cell.classes()).toEqual(expect.arrayContaining(['text-end', 'tabular-nums']));
    });

    it('renders skeleton rows while loading an empty list', () => {
        const w = mount(Table, { props: { columns, rows: [], loading: true, skeletonRows: 3 } });
        expect(w.findAll('[data-skeleton-row]')).toHaveLength(3);
    });

    it('keeps rows dimmed while reloading', () => {
        const w = mount(Table, { props: { columns, rows, loading: true } });
        expect(w.findAll('[data-skeleton-row]')).toHaveLength(0);
        expect(w.find('tbody').classes()).toContain('opacity-60');
        expect(w.find('table').attributes('aria-busy')).toBe('true');
    });

    it('reads and persists density per table id', async () => {
        localStorage.setItem('crm.density.orders', 'compact');
        const w = mount(Table, { props: { columns, rows, tableId: 'orders' } });
        expect(w.find('table').attributes('data-density')).toBe('compact');
        await w.find('[data-density-option="comfortable"]').trigger('click');
        expect(w.find('table').attributes('data-density')).toBe('comfortable');
        expect(localStorage.getItem('crm.density.orders')).toBe('comfortable');
    });

    it('selects rows and all rows', async () => {
        const w = mount(Table, { props: { columns, rows, selectable: true, selected: [] } });
        await w.findAll('tbody input[type="checkbox"]')[1].setValue(true);
        expect(w.emitted('update:selected')?.[0]).toEqual([[2]]);
        await w.find('thead input[type="checkbox"]').setValue(true);
        expect(w.emitted('update:selected')?.[1]).toEqual([[1, 2]]);
    });

    it('renders the totals slot in a tfoot', () => {
        const w = mount(Table, { props: { columns, rows }, slots: { totals: '<tr><td>sum</td><td>30</td></tr>' } });
        expect(w.find('tfoot').text()).toContain('30');
    });

    it('deselects all when every row is selected', async () => {
        const w = mount(Table, { props: { columns, rows, selectable: true, selected: [1, 2] } });
        expect((w.find('thead input[type="checkbox"]').element as HTMLInputElement).checked).toBe(true);
        await w.find('thead input[type="checkbox"]').setValue(false);
        expect(w.emitted('update:selected')?.[0]).toEqual([[]]);
    });

    it('marks the header checkbox indeterminate for a partial selection', () => {
        const w = mount(Table, { props: { columns, rows, selectable: true, selected: [1] } });
        const box = w.find('thead input[type="checkbox"]').element as HTMLInputElement;
        expect(box.indeterminate).toBe(true);
        expect(box.checked).toBe(false);
    });

    it('does not treat keys on the row checkbox as a row activation', async () => {
        const w = mount(Table, { props: { columns, rows, selectable: true, clickable: true, selected: [] } });
        await w.find('tbody input[type="checkbox"]').trigger('keydown', { key: 'Enter' });
        expect(w.emitted('rowClick')).toBeUndefined();
        await w.find('tbody tr').trigger('keydown', { key: 'Enter' });
        expect(w.emitted('rowClick')?.[0]).toEqual([rows[0]]);
    });

    it('sticks the checkbox column, not the data column, when both options are on', () => {
        const w = mount(Table, { props: { columns, rows, selectable: true, stickyFirstColumn: true } });
        const tds = w.findAll('tbody tr')[0].findAll('td');
        expect(tds[0].classes()).toContain('crm-sticky-first');
        expect(tds[1].classes()).not.toContain('crm-sticky-first');
    });
});
