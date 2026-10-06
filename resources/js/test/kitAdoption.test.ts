import { describe, expect, it } from 'vitest';

const sources = import.meta.glob('../pages/**/*.vue', { query: '?raw', import: 'default', eager: true }) as Record<string, string>;
const pages: Record<string, string> = Object.fromEntries(
    Object.entries(sources).map(([path, source]) => [path.replace('../pages/', '').replace(/\.vue$/, ''), source]),
);

/** Pages already moved to the S0 kit. Each sweep task (13-20) appends its group; the test fails until they comply. */
const SWEPT: string[] = [
    // Task 13
    'Orders/Index', 'Orders/Show', 'Customers/Index', 'Customers/Show',
];

/** Raw tables allowed only on pages S1/S2 replace; they must sit in the shared sticky scroll box. */
const RAW_TABLE_OK = new Set(['Ads/Actions', 'Ads/Campaigns', 'Ads/Creatives', 'Ads/Materials/Index']);
/** Raw tables that are layout inside a popover (not a list): allowed anywhere. */
// Tempered: a match can never cross a `</PopoverContent>`, so it cannot swallow a list table that follows a filter popover.
const POPOVER_TABLE = /<PopoverContent\b(?:(?!<\/PopoverContent>)[\s\S])*?<table[\s\S]*?<\/PopoverContent>/g;

/** No PageHeader by design (workspace layouts, auth cards, the settings layout renders its own). */
const NO_PAGE_HEADER = new Set([
    'Inbox', 'Board', 'Error', 'Onboarding', 'settings/BotFlows',
    'auth/ConfirmPassword', 'auth/ForgotPassword', 'auth/Login', 'auth/ResetPassword', 'auth/VerifyEmail',
    'settings/Appearance', 'settings/Notifications', 'settings/Password', 'settings/Profile',
]);

describe('S0 kit adoption', () => {
    it('sees all 62 pages and only real ones are listed', () => {
        expect(Object.keys(pages)).toHaveLength(62);
        for (const name of SWEPT) expect(pages[name], name).toBeDefined();
    });

    it.each(SWEPT)('%s follows the kit', (name) => {
        const source = pages[name];
        const withoutPopoverTables = source.replace(POPOVER_TABLE, '');

        // R1 tables: DataTable, or (S1/S2-replaced pages) a raw table inside the sticky box.
        if (/<table[\s>]/.test(withoutPopoverTables)) {
            expect(RAW_TABLE_OK.has(name), `${name}: raw <table>`).toBe(true);
            expect(source, `${name}: raw table outside .table-scroll-box`).toContain('table-scroll-box');
            expect(source, `${name}: raw table without .crm-sticky-head`).toContain('crm-sticky-head');
        }
        // R2 one PageHeader.
        if (!NO_PAGE_HEADER.has(name)) expect(source, `${name}: no PageHeader`).toContain('<PageHeader');
        // R3 every DataTable remembers its density: `table-id` is its first attribute.
        const tables = source.match(/<DataTable\b/g)?.length ?? 0;
        const withId = source.match(/<DataTable\s+table-id="/g)?.length ?? 0;
        expect(withId, `${name}: DataTable without table-id first`).toBe(tables);
        // R4 search boxes live in the FilterBar.
        expect(source, `${name}: raw search input`).not.toMatch(/<input[^>]*type="search"/);
        // R5 submit spinners come from <Button :loading>.
        expect(source, `${name}: hand-made submit spinner`).not.toMatch(/<LoaderCircle v-if="(busy|saving|submitting|processing|form\.processing)"/);
    });
});
