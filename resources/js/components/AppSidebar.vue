<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useI18n } from '@/composables/useI18n';
import { type NavItem, type SharedData } from '@/types';
import type { Role } from '@/types/crm';
import { Link, usePage } from '@inertiajs/vue3';
import { BarChart3, FlaskConical, Inbox, MessagesSquare, Package, Settings, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();
const { t, dir } = useI18n();

const rank: Record<Role, number> = { moderator: 1, supervisor: 2, admin: 3 };

const role = computed<Role>(() => page.props.auth.user?.role ?? 'moderator');
const allows = (min: Role) => rank[role.value] >= rank[min];

// Nav by role (spec §6): reports/settings subsets for supervisor+, admin-only tools last.
const mainNavItems = computed<NavItem[]>(() => {
    const reports: NavItem[] = [{ title: t('nav.reports_me'), href: '/reports/me' }];
    const settings: NavItem[] = [{ title: t('nav.settings_profile'), href: '/settings/profile' }];

    if (allows('supervisor')) {
        reports.push(
            { title: t('nav.reports_team'), href: '/reports/team' },
            { title: t('nav.reports_bot'), href: '/reports/bot' },
            { title: t('nav.reports_activity'), href: '/reports/activity' },
        );
        settings.push(
            { title: t('nav.settings_bot'), href: '/settings/bot' },
            { title: t('nav.settings_quick_replies'), href: '/settings/quick-replies' },
            { title: t('nav.settings_tags'), href: '/settings/tags' },
        );
    }

    if (allows('admin')) {
        reports.push({ title: t('nav.reports_latency'), href: '/reports/latency' });
        settings.push(
            { title: t('nav.settings_users'), href: '/settings/users' },
            { title: t('nav.settings_channels'), href: '/settings/channels' },
            { title: t('nav.settings_cities'), href: '/settings/cities' },
        );
    }

    const items: NavItem[] = [
        { title: t('nav.inbox'), href: '/inbox', icon: Inbox },
        { title: t('nav.comments'), href: '/comments', icon: MessagesSquare },
        { title: t('nav.orders'), href: '/orders', icon: Package },
        { title: t('nav.customers'), href: '/customers', icon: Users },
        { title: t('nav.reports'), href: '/reports', icon: BarChart3, children: reports },
        { title: t('nav.settings'), href: '/settings', icon: Settings, children: settings },
    ];

    if (allows('admin')) {
        items.push({ title: t('nav.simulator'), href: '/simulator', icon: FlaskConical });
    }

    return items;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset" :side="dir === 'rtl' ? 'right' : 'left'">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link href="/inbox">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" :label="t('nav.section')" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
