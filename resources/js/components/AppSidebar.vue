<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import { Sidebar, SidebarContent, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarRail } from '@/components/ui/sidebar';
import { useI18n } from '@/composables/useI18n';
import { buildHref, carryQuery, readQuery } from '@/lib/adsFilters';
import { buildNav, type NavBadges } from '@/lib/navMenu';
import { logoHref } from '@/lib/today';
import { type SharedData } from '@/types';
import type { Role } from '@/types/crm';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();
const { t, dir } = useI18n();

const role = computed<Role>(() => page.props.auth.user?.role ?? 'moderator');

/** Waiting work: the CRM counts (shared `navBadges`) plus the ads counters the Ads Hub already shares. */
const badges = computed<NavBadges>(() => {
    const counters = page.props.adsCounters ?? null;

    return {
        ...((page.props.navBadges as NavBadges | null | undefined) ?? {}),
        decisions: (page.props.adsDecisions as number | null | undefined) ?? null,
        launches: counters?.buyer_review ?? 0,
        launches_content: counters?.content_returned ?? 0,
    };
});

const items = computed(() => {
    const onboarding = page.props.onboarding as { done: number; total: number } | null | undefined;
    // The ads report links carry the shared filters (U 4.4).
    const search = page.url.includes('?') ? page.url.slice(page.url.indexOf('?')) : '';

    return buildNav({
        role: role.value,
        canSeeBoard: page.props.canSeeBoard === true,
        devTools: page.props.devTools === true,
        onboarding: onboarding ?? null,
        badges: badges.value,
        adsQuery: buildHref('', carryQuery(readQuery(search))),
        t,
    });
});
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar" :side="dir === 'rtl' ? 'right' : 'left'">
        <SidebarHeader class="h-[60px] justify-center border-b border-rail-line px-2.5">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child class="hover:bg-rail-2 hover:text-white">
                        <Link :href="logoHref(role)">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="scrollbar-thin">
            <NavMain :items="items" />
        </SidebarContent>

        <SidebarRail />
    </Sidebar>
    <slot />
</template>
