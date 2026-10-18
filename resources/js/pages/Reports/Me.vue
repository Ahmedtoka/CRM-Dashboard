<script setup lang="ts">
import PageHeader from '@/components/crm/PageHeader.vue';
import MyPointsCard from '@/components/crm/queue/MyPointsCard.vue';
import PeriodControls from '@/components/crm/reports/PeriodControls.vue';
import UserReportView from '@/components/crm/UserReportView.vue';
import { useI18n } from '@/composables/useI18n';
import { useReportFilters } from '@/composables/useReportFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import type { HeatmapGrid, ManagedUser, ReportRange, UserMetrics } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import type { PointsLine, PointsSummary } from '@/types/queue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

/** «أدائي» (mockup V.me): her ledger with «نقطي» in it, then every points line with its reason, then her numbers. */
const props = defineProps<{
    range: ReportRange;
    platform: PlatformValue | null;
    user: ManagedUser;
    metrics: UserMetrics;
    heatmap: HeatmapGrid;
    queue_points?: PointsSummary & { ledger: PointsLine[] };
}>();

const { t } = useI18n();
const { visit } = useReportFilters();
const showPoints = computed(() => !!props.queue_points && (props.queue_points.ledger.length > 0 || props.queue_points.total !== 0));
const breadcrumbs = computed(() => [{ title: t('reports.me_title'), href: '/reports/me' }]);
</script>

<template>
    <Head :title="t('reports.me_title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('reports.me_title')" :description="t('reports.hub.me_description')">
                <PeriodControls :range="range" :platform="platform" @change="visit" />
            </PageHeader>

            <UserReportView
                :range="range"
                :platform="platform"
                :user="user"
                :metrics="metrics"
                :heatmap="heatmap"
                :points="queue_points ? queue_points.total : null"
            >
                <template #after-ledger>
                    <MyPointsCard v-if="showPoints && queue_points" :points="queue_points" />
                </template>
            </UserReportView>
        </div>
    </AppLayout>
</template>
