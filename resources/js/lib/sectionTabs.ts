import type { Role } from '@/types/crm';

/** One tab of a merged section (IA D1–D14): a link with its own URL. */
export interface SectionTab {
    label: string;
    href: string;
    /** Waiting work on this tab; hidden when 0. */
    badge?: number | null;
    /** The badge turns red (past its SLA). */
    urgent?: boolean;
    /** Other path prefixes that belong to this tab (a drill-down such as /reports/users/7 under «الأداء»). */
    match?: string[];
}

type Translate = (key: string, params?: Record<string, string | number>) => string;

const manager = (role: Role | null | undefined) => role === 'admin' || role === 'supervisor';

function split(href: string): [string, URLSearchParams] {
    const [path, query = ''] = href.split('?');

    return [path, new URLSearchParams(query)];
}

/**
 * The tab the page belongs to: same path (or a `match`/child path) and every query key the tab's own URL names equal
 * to the page's. The most specific candidate wins (an exact path beats a child path, `?tab=analytics` beats the bare
 * path), so /orders?tab=analytics lights «التحليل» while /orders?status=paid stays on «كل الأوردرات». -1 = none.
 */
export function activeTabIndex(tabs: SectionTab[], url: string): number {
    const [path, query] = split(url);
    const prefix = (p: string) => path === p || path.startsWith(`${p}/`);
    let best = -1;
    let bestScore = 0;

    tabs.forEach((tab, i) => {
        const [tabPath, tabQuery] = split(tab.href);
        let score = path === tabPath ? 2 : prefix(tabPath) || (tab.match ?? []).some(prefix) ? 1 : 0;
        if (score === 0) return;
        for (const [key, value] of tabQuery) {
            if (query.get(key) !== value) return;
            score += 10;
        }
        if (score > bestScore) {
            best = i;
            bestScore = score;
        }
    });

    return best;
}

/** Keeps the page's own filters on the tabs of the same path (range, search…); `tab`, `page` and `sort` belong to one tab. */
export function carryFilters(tabs: SectionTab[], url: string): SectionTab[] {
    const [path, query] = split(url);
    for (const key of ['tab', 'page', 'sort']) query.delete(key);
    if ([...query.keys()].length === 0) return tabs;

    return tabs.map((tab) => {
        const [tabPath, tabQuery] = split(tab.href);
        if (tabPath !== path) return tab;
        const merged = new URLSearchParams(query);
        for (const [k, v] of tabQuery) merged.set(k, v);

        return { ...tab, href: `${tabPath}?${merged.toString()}` };
    });
}

/** The tab strips, one per merged section (IA §3.3), filtered by role (IA §3.5). */
export const sectionTabs = {
    /** D1: «النهارده» › الملخص · الإعلانات. The buyer's «النهارده» is the ads tab alone (no strip). */
    today: (t: Translate, role: Role | null | undefined): SectionTab[] =>
        manager(role)
            ? [
                  { label: t('section_tabs.today.summary'), href: '/today' },
                  { label: t('section_tabs.today.ads'), href: '/today/ads' },
              ]
            : [],

    /** D2: «الأوردرات» › كل الأوردرات · التأكيدات · التحليل · من الإعلانات (the last two for managers). */
    orders: (t: Translate, role: Role | null | undefined, confirmations?: number | null): SectionTab[] => [
        { label: t('section_tabs.orders.all'), href: '/orders' },
        { label: t('section_tabs.orders.confirmations'), href: '/orders/confirmations', badge: confirmations },
        ...(manager(role)
            ? [
                  { label: t('section_tabs.orders.analytics'), href: '/orders?tab=analytics' },
                  { label: t('section_tabs.orders.ads'), href: '/orders?tab=ads' },
              ]
            : []),
    ],

    /** D12: «محتاج قرار» › مفتوح · موافقات الإطلاق · مأجّل · اتقفل · السجل. */
    decisions: (t: Translate, approvals?: number | null): SectionTab[] => [
        { label: t('section_tabs.decisions.open'), href: '/ads/decisions' },
        { label: t('section_tabs.decisions.approvals'), href: '/ads/approvals', badge: approvals },
        { label: t('section_tabs.decisions.snoozed'), href: '/ads/decisions?tab=snoozed' },
        { label: t('section_tabs.decisions.closed'), href: '/ads/decisions?tab=closed' },
        { label: t('section_tabs.decisions.log'), href: '/ads/decisions?tab=log' },
    ],

    /** D11: «المكتبة» › المواد · المجموعات · المخزون. */
    library: (t: Translate): SectionTab[] => [
        { label: t('section_tabs.library.materials'), href: '/ads/materials' },
        { label: t('section_tabs.library.collections'), href: '/ads/collections' },
        { label: t('section_tabs.library.stock'), href: '/ads/stock' },
    ],

    /** D10: «الفريق» › الأداء · الطابور · الردود الجاهزة; a person's page belongs to «الأداء». */
    team: (t: Translate): SectionTab[] => [
        { label: t('section_tabs.team.performance'), href: '/reports/team', match: ['/reports/users'] },
        { label: t('section_tabs.team.queue'), href: '/reports/queue' },
        { label: t('section_tabs.team.outcomes'), href: '/reports/outcomes' },
        { label: t('section_tabs.team.quick_replies'), href: '/reports/quick-replies' },
    ],

    /** D8: «الفلوهات» › الفلوهات · النوايا. */
    botFlows: (t: Translate): SectionTab[] => [
        { label: t('nav.bot_flows'), href: '/bot/flows' },
        { label: t('nav.bot_intents'), href: '/bot/intents' },
    ],

    /** D7: «كلام البوت» › الردود · المعلومات والسياسات · الترجمة. */
    botCopy: (t: Translate): SectionTab[] => [
        { label: t('nav.bot_replies'), href: '/bot/replies' },
        { label: t('nav.bot_knowledge'), href: '/bot/knowledge' },
        { label: t('nav.bot_translations'), href: '/bot/translations' },
    ],

    /** D9: «التجربة» › الروابط · جلسات الفريق. */
    botTesting: (t: Translate): SectionTab[] => [
        { label: t('nav.bot_test_links'), href: '/bot/test-links' },
        { label: t('nav.bot_test_sessions'), href: '/bot/test-sessions' },
    ],

    /** «التعلم» › الاقتراحات · الملاحظات. */
    botLearning: (t: Translate, pending?: number | null, feedback?: number | null): SectionTab[] => [
        { label: t('section_tabs.learning.suggestions'), href: '/bot/learning', badge: pending },
        { label: t('section_tabs.learning.notes'), href: '/bot/learning?tab=notes' },
        // b2: the team's «رد البوت ده غلط» notes, turned into suggestions from here.
        { label: t('bot_brain.nav.feedback'), href: '/bot/feedback', badge: feedback },
    ],

    /** b2: «عقل البوت» › عقل البوت · التكلفة · إزاي نطوّر البوت. */
    botBrain: (t: Translate): SectionTab[] => [
        { label: t('bot_brain.nav.brain'), href: '/bot/brain' },
        { label: t('bot_brain.nav.costs'), href: '/bot/costs' },
        { label: t('bot_brain.nav.guide'), href: '/bot/guide' },
    ],

    /** D5: «حسابي» › البيانات · كلمة السر · المظهر · الإشعارات (the inner side menu is gone). */
    account: (t: Translate): SectionTab[] => [
        { label: t('section_tabs.account.profile'), href: '/settings/profile' },
        { label: t('section_tabs.account.password'), href: '/settings/password' },
        { label: t('section_tabs.account.appearance'), href: '/settings/appearance' },
        { label: t('section_tabs.account.notifications'), href: '/settings/notifications' },
    ],

    /** D3: «القنوات» › الربط · حالة الـ Webhooks. */
    channels: (t: Translate): SectionTab[] => [
        { label: t('section_tabs.channels.link'), href: '/settings/integrations' },
        { label: t('section_tabs.channels.webhooks'), href: '/settings/integrations?tab=webhooks' },
    ],

    /** D4: «شوبيفاي» › الربط والحالة · المطابقة. */
    shopify: (t: Translate): SectionTab[] => [
        { label: t('section_tabs.shopify.status'), href: '/settings/shopify' },
        { label: t('section_tabs.shopify.reconcile'), href: '/settings/shopify/reconcile' },
    ],

    /** D13: «الحسابات الإعلانية» › الحسابات · المزامنة. */
    adAccounts: (t: Translate): SectionTab[] => [
        { label: t('section_tabs.ad_accounts.accounts'), href: '/settings/ads/accounts' },
        { label: t('section_tabs.ad_accounts.sync'), href: '/settings/ads/sync' },
    ],
};
