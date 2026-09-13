<script setup lang="ts">
import PageHeader from '@/components/crm/PageHeader.vue';
import UserReportView from '@/components/crm/UserReportView.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { HeatmapGrid, ManagedUser, ReportRange, UserMetrics } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ range: ReportRange; platform: PlatformValue | null; user: ManagedUser; metrics: UserMetrics; heatmap: HeatmapGrid }>();

const { t } = useI18n();
const title = computed(() => t('reports.user_title', { name: props.user.name }));
const breadcrumbs = computed(() => [
    { title: t('reports.team_title'), href: '/reports/team' },
    { title: props.user.name, href: `/reports/users/${props.user.id}` },
]);
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <PageHeader :title="title" :description="t(`roles.${user.role}`)" />
            <UserReportView :range="range" :platform="platform" :user="user" :metrics="metrics" :heatmap="heatmap" />
        </div>
    </AppLayout>
</template>
