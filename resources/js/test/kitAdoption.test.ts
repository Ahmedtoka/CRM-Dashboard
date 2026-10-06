import { describe, expect, it } from 'vitest';

const sources = import.meta.glob('../pages/**/*.vue', { query: '?raw', import: 'default', eager: true }) as Record<string, string>;
const pages: Record<string, string> = Object.fromEntries(
    Object.entries(sources).map(([path, source]) => [path.replace('../pages/', '').replace(/\.vue$/, ''), source]),
);

/** Pages already moved to the S0 kit. Each sweep task (13-20) appends its group; the test fails until they comply. */
const SWEPT: string[] = [
    // Task 13
    'Orders/Index', 'Orders/Show', 'Orders/AdOrders', 'Customers/Index', 'Customers/Show',
    // Task 14
    'Inbox', 'Board', 'Comments/Index', 'Cases',
    // Task 15
    'Reports/Activity', 'Reports/Bot', 'Reports/Latency', 'Reports/Me', 'Reports/QuickReplies', 'Reports/Team', 'Reports/TeamTest', 'Reports/User',
    // Task 16
    'Ads/Accounts', 'Ads/BuyerShow', 'Ads/BuyersSetup',
    'Ads/Materials/Collections', 'Ads/Materials/Form', 'Ads/Materials/Index', 'Ads/Materials/Stock', 'Ads/Sync',
    // Task 17
    'settings/Users', 'settings/Tags', 'settings/Cities', 'settings/QuickReplies', 'settings/Queue',
    'settings/Notifications', 'settings/Profile', 'settings/Password', 'settings/Appearance',
    // S1 launch approvals
    'Ads/Launches', 'Ads/Approvals',
    // S2 control room
    'Ads/Explorer', 'Ads/Today', 'Ads/Decisions', 'Ads/Numbers', 'Ads/SetupRules',
    // Task 18
    'settings/Integrations', 'settings/Channels', 'settings/FacebookPages', 'settings/Shopify', 'settings/ShopifyReconcile',
    // Task 19
    'settings/Bot', 'settings/BotFlows', 'settings/BotIntents', 'settings/BotKnowledge', 'settings/BotLearning', 'settings/BotReplies',
    'settings/BotTestLinks', 'settings/BotTranslations', 'Simulator',
    // Task 20
    'auth/ConfirmPassword', 'auth/ForgotPassword', 'auth/Login', 'auth/ResetPassword', 'auth/VerifyEmail', 'Error', 'Onboarding',
    // S4 manager today
    'Today',
];

/**
 * Ads/Materials/Index keeps its raw table and its own search: permanent debt, not a temporary exemption (final review
 * C9). S2 kept the page (it was never replaced), so nothing retires this entry; moving it onto the kit DataTable and
 * the shared filter bar is a separate task. Its raw table must still sit in the shared sticky scroll box.
 */
const RAW_TABLE_OK = new Set(['Ads/Materials/Index']);
const OWN_SEARCH_OK = new Set(['Ads/Materials/Index']);
/** settings/Branches is deleted by FS2 (F4); it keeps its hand-made row icons until then. */
const ROW_ACTIONS_OK = new Set(['settings/Branches']);
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
    it('sees all 63 pages and only real ones are listed', () => {
        expect(Object.keys(pages)).toHaveLength(63);
        for (const name of SWEPT) expect(pages[name], name).toBeDefined();
    });

    it('every page is swept (Task 20 closes the list)', () => {
        expect([...SWEPT].sort()).toEqual(Object.keys(pages).sort());
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
        // R3 every DataTable is identifiable (`data-table-id` hook for tests and screenshots): `table-id` is its first attribute.
        const tables = source.match(/<DataTable\b/g)?.length ?? 0;
        const withId = source.match(/<DataTable\s+table-id="/g)?.length ?? 0;
        expect(withId, `${name}: DataTable without table-id first`).toBe(tables);
        // R4 search boxes live in the FilterBar.
        if (!OWN_SEARCH_OK.has(name)) expect(source, `${name}: raw search input`).not.toMatch(/<input[^>]*type="search"/);
        // R5 submit spinners come from <Button :loading>.
        expect(source, `${name}: hand-made submit spinner`).not.toMatch(/<LoaderCircle v-if="(busy|saving|submitting|processing|form\.processing)"/);
        // R6 row actions are IconAction (icon + tooltip + aria-label, F7): no hand-made buttons in a table's actions cell.
        if (!ROW_ACTIONS_OK.has(name)) {
            for (const cell of source.match(/#cell-actions="[^"]*">[\s\S]*?<\/template>/g) ?? []) {
                expect(cell, `${name}: row action without IconAction`).toContain('<IconAction');
                expect(cell, `${name}: hand-made row action button`).not.toMatch(/<(button|Button|Link)[\s>]/);
            }
        }
    });
});
