<script setup lang="ts">
import ActivityTimeline from '@/components/crm/ActivityTimeline.vue';
import DateRangePicker from '@/components/crm/DateRangePicker.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import Pagination from '@/components/crm/Pagination.vue';
import { useI18n } from '@/composables/useI18n';
import { translate } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import type { ActivityLogItem, Paginated, ReportRange } from '@/types/admin';
import type { UserRef } from '@/types/crm';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Filters {
    user_id: number | null;
    action: string | null;
    platform: string | null;
}

const props = defineProps<{ logs: Paginated<ActivityLogItem>; range: ReportRange; filters: Filters; users: UserRef[]; actions: string[] }>();

const { t, locale } = useI18n();
const page = usePage<SharedData>();

function visit(patch: Partial<Filters & ReportRange>): void {
    const next: Record<string, string | number | null> = { ...props.range, ...props.filters, ...patch };
    const query = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== ''));
    router.get('/reports/activity', query, { preserveState: true, preserveScroll: true, replace: true });
}

// Short human labels for the filter dropdown (timeline sentences keep their full templates).
const actionOptions = computed(() =>
    props.actions.map((action) => {
        const key = `activity_filter.${action}`;
        const label = translate(locale.value, key);
        return { value: action, label: label === key ? action : label };
    }),
);

const selectValue = (event: Event) => (event.target as HTMLSelectElement).value || null;
const selectClass = 'h-9 max-w-[16rem] rounded-md border border-input bg-background px-2 text-xs';
const breadcrumbs = computed(() => [{ title: t('activity.ui.title'), href: '/reports/activity' }]);
</script>

<template>
    <Head :title="t('activity.ui.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('activity.ui.title')" />

            <div class="flex flex-wrap items-center gap-2 rounded-lg bg-card p-3 shadow-card">
                <DateRangePicker :model-value="range" @update:model-value="visit($event)" />
                <select :value="filters.user_id ?? ''" :class="selectClass" :aria-label="t('activity.ui.actor')" @change="visit({ user_id: Number(selectValue($event)) || null })">
                    <option value="">{{ t('activity.ui.all_actors') }}</option>
                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <select :value="filters.action ?? ''" :class="selectClass" :aria-label="t('activity.ui.action')" @change="visit({ action: selectValue($event) })">
                    <option value="">{{ t('activity.ui.all_actions') }}</option>
                    <option v-for="a in actionOptions" :key="a.value" :value="a.value">{{ a.label }}</option>
                </select>
                <select :value="filters.platform ?? ''" :class="selectClass" :aria-label="t('ui.platforms')" @change="visit({ platform: selectValue($event) })">
                    <option value="">{{ t('ui.all_platforms') }}</option>
                    <option v-for="p in page.props.platforms" :key="p.value" :value="p.value">{{ p.label }}</option>
                </select>
            </div>

            <div>
                <ActivityTimeline :logs="logs.data" />
                <Pagination :page="logs" />
            </div>
        </div>
    </AppLayout>
</template>
