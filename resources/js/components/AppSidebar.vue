<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import { Sidebar, SidebarContent, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarRail } from '@/components/ui/sidebar';
import { useI18n } from '@/composables/useI18n';
import { adsNavChildren } from '@/lib/adsNav';
import { logoHref, todayNavItem } from '@/lib/today';
import { type NavItem, type SharedData } from '@/types';
import type { Role } from '@/types/crm';
import { Link, usePage } from '@inertiajs/vue3';
import { BarChart3, ClipboardList, FlaskConical, Gauge, Images, Inbox, LayoutGrid, Megaphone, MessagesSquare, Package, Rocket, Settings, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();
const { t, dir } = useI18n();

const rank: Record<Role, number> = { moderator: 1, supervisor: 2, admin: 3, media_buyer: 0, content: 0 };

const role = computed<Role>(() => page.props.auth.user?.role ?? 'moderator');
const allows = (min: Role) => rank[role.value] >= rank[min];

// Ads Hub (spec §8): report pages for supervisor+ and media buyers (a buyer's «media buyers» page is
// their own card), management pages for supervisor+, the materials library for content.
const adsGroup = computed<NavItem | null>(() => {
    if (role.value === 'content') {
        const materials: NavItem = { title: t('nav.ads_materials'), href: '/ads/materials' };
        const launches: NavItem = { title: t('nav.ads_launches'), href: '/ads/launches', badge: (page.props.adsCounters as { content_returned?: number } | null)?.content_returned ?? 0 };
        return {
            title: t('nav.ads'),
            href: '/ads',
            icon: Images,
            children: [materials, launches, { title: t('nav.ads_collections'), href: '/ads/collections' }, { title: t('nav.ads_stock'), href: '/ads/stock' }],
        };
    }
    if (role.value !== 'media_buyer' && !allows('supervisor')) return null;

    // D8: six items; approvals live under «محتاج قرار» (match /ads/approvals), drafts to review count on «المكتبة».
    const counters = (page.props.adsCounters ?? null) as { content_returned?: number; buyer_review?: number } | null;
    const children = adsNavChildren(
        role.value,
        t,
        {
            decisions: (page.props.adsDecisions as number | null | undefined) ?? null,
            library: (counters?.buyer_review ?? 0) + (counters?.content_returned ?? 0),
        },
        page.url.includes('?') ? page.url.slice(page.url.indexOf('?')) : '',
    );

    return { title: t('nav.ads'), href: '/ads', icon: Megaphone, children };
});

// Nav by role (spec §6): reports/settings subsets for supervisor+, admin-only tools last.
// Settings children carry a `section` so NavMain renders them under small headings.
/** Admins and supervisors start on «النهارده» (control room S4); the logo leads to each one's home. */
const today = computed(() => todayNavItem(role.value, t('nav.today'), Gauge));

const mainNavItems = computed<NavItem[]>(() => {
    // Ads roles see only the Ads Hub (RestrictAdsRoles keeps them out of everything else).
    if (role.value === 'media_buyer' || role.value === 'content') return adsGroup.value ? [adsGroup.value] : [];

    const devTools = page.props.devTools === true;
    const reports: NavItem[] = [{ title: t('nav.reports_me'), href: '/reports/me' }];

    const account = t('nav.settings_groups.account');
    const bot = t('nav.settings_groups.bot');
    const store = t('nav.settings_groups.store');
    const team = t('nav.settings_groups.team');

    // Saved replies (spec §2.3): shared read-only, personal manageable — every
    // signed-in user can reach the page, not just supervisor+.
    const settings: NavItem[] = [
        { title: t('nav.settings_profile_item'), href: '/settings/profile', section: account },
        { title: t('nav.settings_quick_replies'), href: '/settings/quick-replies', section: account },
    ];

    if (allows('supervisor')) {
        reports.push(
            { title: t('nav.reports_team'), href: '/reports/team' },
            { title: t('nav.reports_bot'), href: '/reports/bot' },
            { title: t('nav.reports_activity'), href: '/reports/activity' },
            { title: t('nav.reports_quick_replies'), href: '/reports/quick-replies' },
            { title: t('nav.reports_team_test'), href: '/reports/team-test' },
        );
        settings.push(
            { title: t('nav.settings_bot'), href: '/settings/bot', section: bot },
            { title: t('nav.settings_bot_replies'), href: '/settings/bot-replies', section: bot },
            { title: t('nav.settings_bot_intents'), href: '/settings/bot-intents', section: bot },
            { title: t('nav.settings_bot_flows'), href: '/settings/bot-flows', section: bot },
            { title: t('nav.settings_bot_knowledge'), href: '/settings/bot-knowledge', section: bot },
            { title: t('nav.settings_bot_learning'), href: '/settings/bot-learning', section: bot },
            { title: t('nav.settings_bot_translations'), href: '/settings/bot-translations', section: bot },
            { title: t('nav.settings_test_links'), href: '/settings/bot-test-links', section: bot },
            { title: t('nav.settings_queue'), href: '/settings/queue', section: team },
        );
        if (allows('admin')) {
            settings.push(
                { title: t('nav.settings_cities'), href: '/settings/cities', section: store },
                { title: t('nav.settings_shopify'), href: '/settings/shopify', section: store },
            );
        }
        settings.push({ title: t('nav.settings_tags'), href: '/settings/tags', section: store });
    }

    if (allows('admin')) {
        // Developer-only pages show only with crm.dev_tools on (their routes 404 otherwise).
        if (devTools) {
            reports.push({ title: t('nav.reports_latency'), href: '/reports/latency' });
        }
        settings.push(
            { title: t('nav.settings_users'), href: '/settings/users', section: team },
            { title: t('nav.settings_integrations'), href: '/settings/integrations', section: team },
        );
    }

    const onboarding = page.props.onboarding as { done: number; total: number; complete: boolean; dismissed: boolean } | null | undefined;
    const items: NavItem[] = [
        // «النهارده» (control room S4): the admin/supervisor home, first.
        ...(today.value ? [today.value] : []),
        // «ابدأ من هنا» stays near the top for the admin until every required step is done (2026-09-26).
        ...(onboarding && onboarding.done < onboarding.total
            ? [{ title: `${t('nav.onboarding')} · ${onboarding.done}/${onboarding.total}`, href: '/onboarding', icon: Rocket }]
            : []),
        { title: t('nav.inbox'), href: '/inbox', icon: Inbox },
        ...(page.props.canSeeBoard === true ? [{ title: t('nav.board'), href: '/board', icon: LayoutGrid }] : []),
        { title: t('nav.cases'), href: '/cases', icon: ClipboardList },
        { title: t('nav.comments'), href: '/comments', icon: MessagesSquare },
        { title: t('nav.orders'), href: '/orders', icon: Package },
        { title: t('nav.customers'), href: '/customers', icon: Users },
        ...(adsGroup.value ? [adsGroup.value] : []),
        { title: t('nav.reports'), href: '/reports', icon: BarChart3, children: reports },
        { title: t('nav.settings'), href: '/settings', icon: Settings, children: settings },
    ];

    if (allows('admin') && page.props.devTools === true) {
        items.push({ title: t('nav.simulator'), href: '/simulator', icon: FlaskConical });
    }

    return items;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar" :side="dir === 'rtl' ? 'right' : 'left'">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="logoHref(role)">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" :label="t('nav.section')" />
        </SidebarContent>

        <SidebarRail />
    </Sidebar>
    <slot />
</template>
