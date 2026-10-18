<script setup lang="ts">
import PageHeader from '@/components/crm/PageHeader.vue';
import PeriodControls from '@/components/crm/reports/PeriodControls.vue';
import UserReportView from '@/components/crm/UserReportView.vue';
import { useI18n } from '@/composables/useI18n';
import { useReportFilters } from '@/composables/useReportFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
import type { HeatmapGrid, ManagedUser, ReportRange, UserMetrics } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

/** «الفريق» › one person (mockup V.team drill): the breadcrumb back to the team, her period and her numbers. */
const props = defineProps<{ range: ReportRange; platform: PlatformValue | null; user: ManagedUser; metrics: UserMetrics; heatmap: HeatmapGrid }>();

const { t, locale } = useI18n();
const { visit } = useReportFilters();
const title = computed(() => t('reports.user_title', { name: props.user.name }));
const teamHref = computed(() => `/reports/team?from=${props.range.from}&to=${props.range.to}${props.platform ? `&platform=${props.platform}` : ''}`);
const breadcrumbs = computed(() => [
    { title: t('reports.hub.title'), href: teamHref.value },
    { title: props.user.name, href: `/reports/users/${props.user.id}` },
]);
const crumbs = computed(() => breadcrumbs.value.map((b) => ({ label: b.title, href: b.href })));
const description = computed(() =>
    [
        t('range.summary', { from: formatDate(props.range.from, locale.value), to: formatDate(props.range.to, locale.value) }),
        t(`roles.${props.user.role}`),
    ].join(' · '),
);
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="user.name" :description="description" :breadcrumbs="crumbs">
                <PeriodControls :range="range" :platform="platform" @change="visit" />
            </PageHeader>

            <UserReportView :range="range" :platform="platform" :user="user" :metrics="metrics" :heatmap="heatmap" />
        </div>
    </AppLayout>
</template>
