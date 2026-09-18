<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppTopBar from '@/components/AppTopBar.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
    contentClass?: string;
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    contentClass: undefined,
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="bg-background" :class="contentClass">
            <AppTopBar :breadcrumbs="breadcrumbs">
                <template #search><slot name="topbar-search" /></template>
                <template #actions><slot name="topbar-actions" /></template>
            </AppTopBar>
            <slot />
        </AppContent>
    </AppShell>
</template>
