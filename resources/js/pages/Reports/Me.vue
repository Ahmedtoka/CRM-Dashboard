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

defineProps<{ range: ReportRange; platform: PlatformValue | null; user: ManagedUser; metrics: UserMetrics; heatmap: HeatmapGrid }>();

const { t } = useI18n();
const { visit } = useReportFilters();
const breadcrumbs = computed(() => [{ title: t('reports.me_title'), href: '/reports/me' }]);
</script>

<template>
    <Head :title="t('reports.me_title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('reports.me_title')" :description="user.name">
                <ReportFilters :range="range" :platform="platform" @change="visit" />
            </PageHeader>
            <UserReportView :range="range" :platform="platform" :user="user" :metrics="metrics" :heatmap="heatmap" />
        </div>
    </AppLayout>
</template>
