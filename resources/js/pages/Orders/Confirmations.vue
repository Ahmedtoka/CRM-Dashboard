<script setup lang="ts">
import Callout from '@/components/crm/Callout.vue';
import CallOutcomeActions from '@/components/crm/confirmations/CallOutcomeActions.vue';
import ConfirmationStatusChip from '@/components/crm/confirmations/ConfirmationStatusChip.vue';
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import DateInput from '@/components/crm/DateInput.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import IconAction from '@/components/crm/IconAction.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import Pagination from '@/components/crm/Pagination.vue';
import StatCard from '@/components/crm/StatCard.vue';
import { useI18n } from '@/composables/useI18n';
import { useUrlFilters } from '@/composables/useUrlFilters';
import { useVisitLoading } from '@/composables/useVisitLoading';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatDateTime, formatMoney } from '@/lib/format';
import { formatShare } from '@/lib/today';
import type { Paginated } from '@/types/admin';
import { CONFIRMATION_TABS, type ConfirmationCounts, type ConfirmationRow, type ConfirmationTab } from '@/types/orderConfirmations';
import { Head, Link, router } from '@inertiajs/vue3';
import { ClipboardList, ListChecks, MessagesSquare, Settings } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

// The server still shares the props it read; the page reads the same values from the URL (useUrlFilters).
defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        date: string;
        today: string;
        tab: ConfirmationTab;
        counts: ConfirmationCounts;
        rows: Paginated<ConfirmationRow>;
        /** The call list ignores the day. */
        allDays?: boolean;
        enabled: boolean;
        canEditSettings?: boolean;
    }>(),
    { allDays: false, canEditSettings: false },
);

const { t, locale } = useI18n();
const { loading, track } = useVisitLoading();

const { filters, set } = useUrlFilters({ date: null as string | null, tab: 'all' });
watch(
    () => ({ ...filters.value }),
    (q) => {
        router.get(
            '/orders/confirmations',
            { date: q.date ?? undefined, tab: q.tab === 'all' ? undefined : q.tab },
            track({ only: ['date', 'tab', 'counts', 'rows', 'allDays'], preserveState: true, preserveScroll: true, replace: true }),
        );
    },
);

const activeTab = computed<ConfirmationTab>(() =>
    (CONFIRMATION_TABS as readonly string[]).includes(filters.value.tab) ? (filters.value.tab as ConfirmationTab) : props.tab,
);
const day = computed(() => filters.value.date ?? props.date);

// Rows the call buttons update in place (the row leaves the call list on the next load).
const rows = ref<ConfirmationRow[]>(props.rows.data);
watch(
    () => props.rows.data,
    (data) => (rows.value = data),
);
function onRecorded(row: ConfirmationRow): void {
    rows.value = rows.value.map((r) => (r.id === row.id ? row : r));
    router.reload({ only: ['counts'] });
}

const n = (v: number) => formatCount(v, locale.value);

const counters = computed(() => {
    const c = props.counts;
    return [
        { key: 'sent', value: n(c.sent), tab: 'all' as const, tone: 'default' as const },
        {
            key: 'confirmed',
            value: n(c.confirmed),
            hint:
                c.confirmed_share === null
                    ? undefined
                    : t('order_confirmations.counters.confirmed_share', { share: formatShare(c.confirmed_share, locale.value) }),
            tab: 'confirmed' as const,
            tone: 'positive' as const,
        },
        { key: 'cancel', value: n(c.cancel), tab: 'requests' as const, tone: c.cancel > 0 ? ('negative' as const) : ('default' as const) },
        { key: 'edit', value: n(c.edit), tab: 'requests' as const, tone: 'default' as const },
        {
            key: 'no_response',
            value: n(c.no_response),
            tab: 'needs_call' as const,
            tone: c.no_response > 0 ? ('warning' as const) : ('default' as const),
        },
        { key: 'waiting', value: n(c.waiting), tab: 'all' as const, tone: 'default' as const },
    ];
});

const columns = computed<Column[]>(() => [
    { key: 'order', label: t('order_confirmations.columns.order'), primary: true },
    { key: 'customer', label: t('order_confirmations.columns.customer') },
    { key: 'total', label: t('order_confirmations.columns.total'), numeric: true, hideOnMobile: true },
    { key: 'status', label: t('order_confirmations.columns.status') },
    { key: 'sent', label: t('order_confirmations.columns.sent'), hideOnMobile: true },
    { key: 'answered', label: t('order_confirmations.columns.answered') },
    { key: 'note', label: t('order_confirmations.columns.note'), hideOnMobile: true },
    { key: 'links', label: t('order_confirmations.columns.links'), align: 'end' },
]);

const emptyTitle = computed(() => t(`order_confirmations.empty.${activeTab.value}`));
const isToday = computed(() => day.value === props.today);
const title = computed(() => t('order_confirmations.title'));
const breadcrumbs = computed(() => [
    { title: t('orders.title'), href: '/orders' },
    { title: title.value, href: '/orders/confirmations' },
]);
const crumbs = computed(() => breadcrumbs.value.map((b) => ({ label: b.title, href: b.href })));
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full min-w-0 max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="title" :description="t('order_confirmations.description')" :breadcrumbs="crumbs">
                <IconAction
                    v-if="canEditSettings"
                    :icon="Settings"
                    variant="outline"
                    href="/settings/order-confirmations"
                    :label="t('order_confirmations.settings.title')"
                />
            </PageHeader>

            <Callout v-if="!enabled" tone="warning" :title="t('order_confirmations.off.title')" data-confirmations-off>
                {{ t('order_confirmations.off.body') }}
                <Link v-if="canEditSettings" href="/settings/order-confirmations" class="font-medium underline">{{
                    t('order_confirmations.off.action')
                }}</Link>
            </Callout>

            <div class="flex flex-wrap items-end gap-3 rounded-lg bg-card p-3 shadow-card">
                <label class="min-w-0">
                    <span class="mb-1 block text-2xs font-medium text-muted-foreground">{{ t('order_confirmations.day') }}</span>
                    <DateInput :model-value="day" :max="today" @update:model-value="set({ date: $event && $event !== today ? $event : null })" />
                </label>
                <button
                    v-if="!isToday"
                    type="button"
                    class="inline-flex h-9 items-center rounded-md border bg-background px-3 text-xs font-medium hover:bg-muted"
                    @click="set({ date: null })"
                >
                    {{ t('order_confirmations.back_to_today') }}
                </button>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6" data-confirmation-counters>
                <button
                    v-for="c in counters"
                    :key="c.key"
                    type="button"
                    class="rounded-lg text-start focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :data-counter="c.key"
                    @click="set({ tab: c.tab })"
                >
                    <StatCard :label="t(`order_confirmations.counters.${c.key}`)" :value="c.value" :hint="c.hint" :tone="c.tone" />
                </button>
            </div>

            <div class="flex flex-wrap gap-1.5" role="tablist" :aria-label="title">
                <button
                    v-for="tab in CONFIRMATION_TABS"
                    :key="tab"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === tab"
                    class="inline-flex h-9 items-center gap-1.5 rounded-full px-4 text-xs font-medium"
                    :class="
                        activeTab === tab ? 'bg-primary text-primary-foreground' : 'bg-card text-muted-foreground shadow-card hover:text-foreground'
                    "
                    :data-tab="tab"
                    @click="set({ tab })"
                >
                    {{ t(`order_confirmations.tabs.${tab}`) }}
                    <span class="tabular-nums opacity-80">{{ n(counts.tabs[tab] ?? 0) }}</span>
                </button>
            </div>
            <p v-if="allDays" class="text-2xs text-muted-foreground" data-all-days>{{ t('order_confirmations.all_days_hint') }}</p>

            <div role="tabpanel" class="min-w-0">
                <DataTable
                    table-id="order-confirmations"
                    :columns="columns"
                    :rows="rows"
                    :loading="loading"
                    sticky-header
                    :empty="emptyTitle"
                    :caption="title"
                >
                    <template #empty>
                        <EmptyState :icon="ListChecks" :title="emptyTitle" />
                    </template>
                    <template #cell-order="{ row }">
                        <Link v-if="row.order" :href="`/orders/${row.order.id}`" class="font-semibold text-primary hover:underline" dir="ltr">{{
                            row.order.name
                        }}</Link>
                        <span v-else class="text-muted-foreground">—</span>
                    </template>
                    <template #cell-customer="{ row }">
                        <span class="block max-w-[12rem] truncate" dir="auto">{{ row.order?.customer_name ?? '—' }}</span>
                        <span v-if="row.phone" class="block text-2xs text-muted-foreground" dir="ltr">{{ row.phone }}</span>
                    </template>
                    <template #cell-total="{ row }">
                        <span class="whitespace-nowrap font-bold tabular-nums">{{ row.order ? formatMoney(row.order.total, locale) : '—' }}</span>
                    </template>
                    <template #cell-status="{ row }">
                        <ConfirmationStatusChip :status="row.status" :skip-reason="row.skip_reason" :last-error="row.last_error" />
                    </template>
                    <template #cell-sent="{ row }">
                        <span class="whitespace-nowrap tabular-nums text-muted-foreground">{{
                            row.sent_at ? formatDateTime(row.sent_at, locale) : '—'
                        }}</span>
                    </template>
                    <template #cell-answered="{ row }">
                        <span v-if="row.answered_at" class="whitespace-nowrap tabular-nums text-muted-foreground">{{
                            formatDateTime(row.answered_at, locale)
                        }}</span>
                        <span v-else-if="row.called_at" class="block text-2xs text-muted-foreground">
                            <span class="tabular-nums">{{ formatDateTime(row.called_at, locale) }}</span>
                            <span v-if="row.caller" class="block" dir="auto">{{ t('order_confirmations.called_by', { name: row.caller }) }}</span>
                        </span>
                        <span v-else class="text-muted-foreground">—</span>
                    </template>
                    <template #cell-note="{ row }">
                        <span
                            v-if="row.edit_note"
                            class="line-clamp-2 block max-w-[16rem] whitespace-pre-line text-xs"
                            dir="auto"
                            :title="row.edit_note"
                            >{{ row.edit_note }}</span
                        >
                        <span v-else class="text-muted-foreground">—</span>
                    </template>
                    <template #cell-links="{ row }">
                        <span class="inline-flex items-center gap-0.5">
                            <CallOutcomeActions v-if="row.needs_call" :confirmation-id="row.id" @recorded="onRecorded" />
                            <IconAction
                                v-if="row.case_id"
                                :icon="ClipboardList"
                                size="sm"
                                :href="`/cases?open=${row.case_id}`"
                                :label="t('order_confirmations.open_case')"
                            />
                            <IconAction
                                v-if="row.conversation_id"
                                :icon="MessagesSquare"
                                size="sm"
                                :href="`/inbox?c=${row.conversation_id}`"
                                :label="t('ui.open_conversation')"
                            />
                        </span>
                    </template>
                </DataTable>
                <Pagination :page="props.rows" />
            </div>
        </div>
    </AppLayout>
</template>
