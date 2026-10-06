<script setup lang="ts">
import ActivityTimeline from '@/components/crm/ActivityTimeline.vue';
import DateRangePicker from '@/components/crm/DateRangePicker.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FilterBar from '@/components/crm/FilterBar.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import Pagination from '@/components/crm/Pagination.vue';
import SkeletonList from '@/components/crm/SkeletonList.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useUrlFilters } from '@/composables/useUrlFilters';
import { useVisitLoading } from '@/composables/useVisitLoading';
import { activityActionLabel } from '@/lib/activity';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
import type { SharedData } from '@/types';
import type { ActivityLogItem, Paginated, ReportRange } from '@/types/admin';
import type { UserRef } from '@/types/crm';
import { Head, router, usePage } from '@inertiajs/vue3';
import { History } from 'lucide-vue-next';
import { computed, watch } from 'vue';

// The server still shares `filters`; the page reads the same keys from the URL (useUrlFilters).
defineOptions({ inheritAttrs: false });

const props = defineProps<{ logs: Paginated<ActivityLogItem>; range: ReportRange; users: UserRef[]; actions: string[] }>();

const { t, locale } = useI18n();
const page = usePage<SharedData>();
const { loading, track } = useVisitLoading();

// Filters live in the URL; an absent range means the server's default (props.range says which).
const { filters, set, clear, activeKeys, query } = useUrlFilters({
    user_id: null as string | null,
    action: null as string | null,
    platform: null as string | null,
    from: null as string | null,
    to: null as string | null,
});
watch(query, (q) => {
    router.get('/reports/activity', q, track({ only: ['logs', 'range', 'filters'], preserveState: true, preserveScroll: true, replace: true }));
});

// Short human labels for the filter dropdown (timeline sentences keep their full templates).
const actionOptions = computed(() =>
    props.actions.map((action) => ({ value: action, label: activityActionLabel(action, locale.value) })),
);

const rangeLabel = computed(() => t('range.summary', { from: formatDate(props.range.from, locale.value), to: formatDate(props.range.to, locale.value) }));
const chips = computed(() => {
    const f = filters.value;
    const out: { key: string; label: string }[] = [];
    if (f.user_id) out.push({ key: 'user_id', label: props.users.find((u) => String(u.id) === f.user_id)?.name ?? t('activity.ui.actor') });
    if (f.action) out.push({ key: 'action', label: activityActionLabel(f.action, locale.value) });
    if (f.platform) out.push({ key: 'platform', label: page.props.platforms.find((p) => p.value === f.platform)?.label ?? f.platform });
    if (f.from || f.to) out.push({ key: 'date', label: rangeLabel.value });
    return out;
});
const moreCount = computed(() => activeKeys.value.filter((k) => k === 'platform').length + (filters.value.from || filters.value.to ? 1 : 0));
const summary = computed(() =>
    [t('ui.results', { n: props.logs.meta?.total ?? props.logs.data.length }), rangeLabel.value, ...chips.value.filter((c) => c.key !== 'date').map((c) => c.label)].join(' · '),
);

function removeChip(key: string): void {
    if (key === 'date') set({ from: null, to: null });
    else set({ [key]: null } as Partial<typeof filters.value>);
}

const selectValue = (event: Event) => (event.target as HTMLSelectElement).value || null;
const selectClass = 'h-9 w-full max-w-[16rem] rounded-md border border-input bg-background px-2 text-xs sm:w-auto';
const fieldLabel = 'mb-1 block text-2xs font-medium text-muted-foreground';
const breadcrumbs = computed(() => [{ title: t('activity.ui.title'), href: '/reports/activity' }]);
</script>

<template>
    <Head :title="t('activity.ui.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('activity.ui.title')" />

            <div class="rounded-lg bg-card p-3 shadow-card">
                <FilterBar :chips="chips" :more-count="moreCount" :summary="summary" @remove="removeChip" @clear="clear()">
                    <template #inline>
                        <select :value="filters.user_id ?? ''" :class="selectClass" :aria-label="t('activity.ui.actor')" @change="set({ user_id: selectValue($event) })">
                            <option value="">{{ t('activity.ui.all_actors') }}</option>
                            <option v-for="u in users" :key="u.id" :value="String(u.id)">{{ u.name }}</option>
                        </select>
                        <select :value="filters.action ?? ''" :class="selectClass" :aria-label="t('activity.ui.action')" @change="set({ action: selectValue($event) })">
                            <option value="">{{ t('activity.ui.all_actions') }}</option>
                            <option v-for="a in actionOptions" :key="a.value" :value="a.value">{{ a.label }}</option>
                        </select>
                    </template>
                    <template #more>
                        <label class="block">
                            <span :class="fieldLabel">{{ t('ui.platforms') }}</span>
                            <select :value="filters.platform ?? ''" :class="[selectClass, 'max-w-none sm:w-full']" @change="set({ platform: selectValue($event) })">
                                <option value="">{{ t('ui.all_platforms') }}</option>
                                <option v-for="p in page.props.platforms" :key="p.value" :value="p.value">{{ p.label }}</option>
                            </select>
                        </label>
                        <div>
                            <span :class="fieldLabel">{{ t('range.label') }}</span>
                            <DateRangePicker :model-value="range" @update:model-value="set({ from: $event.from, to: $event.to })" />
                        </div>
                    </template>
                </FilterBar>
            </div>

            <div>
                <SkeletonList v-if="loading && !logs.data.length" variant="cards" />
                <div v-else-if="!logs.data.length" class="rounded-lg bg-card shadow-card">
                    <EmptyState :icon="History" :title="t('activity.ui.empty')">
                        <template v-if="chips.length" #action>
                            <Button variant="outline" size="sm" @click="clear()">{{ t('ui.clear_filters') }}</Button>
                        </template>
                    </EmptyState>
                </div>
                <template v-else>
                    <ActivityTimeline :logs="logs.data" :class="loading ? 'opacity-60 transition-opacity' : ''" />
                    <Pagination :page="logs" />
                </template>
            </div>
        </div>
    </AppLayout>
</template>
