<script setup lang="ts">
import PageHeader from '@/components/crm/PageHeader.vue';
import ReportFilters from '@/components/crm/ReportFilters.vue';
import UserReportView from '@/components/crm/UserReportView.vue';
import { useI18n } from '@/composables/useI18n';
import { useReportFilters } from '@/composables/useReportFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import type { HeatmapGrid, ManagedUser, ReportRange, UserMetrics } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ range: ReportRange; platform: PlatformValue | null; user: ManagedUser; metrics: UserMetrics; heatmap: HeatmapGrid }>();

const { t } = useI18n();
const { visit } = useReportFilters();
const title = computed(() => t('reports.user_title', { name: props.user.name }));
const breadcrumbs = computed(() => [
    { title: t('reports.team_title'), href: '/reports/team' },
    { title: props.user.name, href: `/reports/users/${props.user.id}` },
]);
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="title" :description="t(`roles.${user.role}`)">
                <ReportFilters :range="range" :platform="platform" @change="visit" />
            </PageHeader>
            <UserReportView :range="range" :platform="platform" :user="user" :metrics="metrics" :heatmap="heatmap" />
        </div>
    </AppLayout>
</template>
