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
import SectionTabs from '@/components/crm/SectionTabs.vue';
import { useI18n } from '@/composables/useI18n';
import { useUrlFilters } from '@/composables/useUrlFilters';
import { useVisitLoading } from '@/composables/useVisitLoading';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatDateTime, formatMoney } from '@/lib/format';
import { sectionTabs } from '@/lib/sectionTabs';
import { formatShare } from '@/lib/today';
import type { SharedData } from '@/types';
import type { Paginated } from '@/types/admin';
import { CONFIRMATION_TABS, type ConfirmationCounts, type ConfirmationRow, type ConfirmationTab } from '@/types/orderConfirmations';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ClipboardList, ListChecks, MessagesSquare, Phone, Settings } from 'lucide-vue-next';
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
/** The shared props the tab strip reads (role, the confirmations badge, the URL). */
const inertiaPage = usePage<SharedData>();
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

/**
 * The tabs as boxes (mockup «التأكيدات»): the call list first, its number red while anyone waits for a call (the SLA
 * colour), the confirmations under it; «الكل» last. Each box is the tab, so one control filters the table.
 */
const BOX_ORDER: ConfirmationTab[] = ['needs_call', 'requests', 'confirmed', 'skipped', 'all'];
const boxes = computed(() =>
    BOX_ORDER.map((tab) => ({ tab, value: props.counts.tabs[tab] ?? 0, urgent: tab === 'needs_call' && (props.counts.tabs[tab] ?? 0) > 0 })),
);

/** The day's numbers the old cards showed, as one line under the boxes, so nothing is lost. */
const dayLine = computed(() => {
    const c = props.counts;
    const parts = [
        `${t('order_confirmations.counters.sent')} ${n(c.sent)}`,
        `${t('order_confirmations.counters.waiting')} ${n(c.waiting)}`,
        `${t('order_confirmations.counters.confirmed')} ${n(c.confirmed)}` +
            (c.confirmed_share === null
                ? ''
                : ` (${t('order_confirmations.counters.confirmed_share', { share: formatShare(c.confirmed_share, locale.value) })})`),
        `${t('order_confirmations.counters.cancel')} ${n(c.cancel)}`,
        `${t('order_confirmations.counters.edit')} ${n(c.edit)}`,
        `${t('order_confirmations.counters.no_response')} ${n(c.no_response)}`,
    ];
    return parts.join(' · ');
});

const columns = computed<Column[]>(() => [
    { key: 'order', label: t('order_confirmations.columns.order'), primary: true },
    { key: 'customer', label: t('order_confirmations.columns.customer') },
    { key: 'total', label: t('order_confirmations.columns.total'), numeric: true, hideOnMobile: true },
    { key: 'status', label: t('order_confirmations.columns.status') },
    { key: 'sent', label: t('order_confirmations.columns.sent'), hideOnMobile: true },
    { key: 'answered', label: t('order_confirmations.columns.answered') },
    { key: 'note', label: t('order_confirmations.columns.note'), hideOnMobile: true },
    { key: 'links', label: t('order_confirmations.columns.links'), align: 'end', wide: true },
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
            <SectionTabs
                :tabs="sectionTabs.orders(t, inertiaPage.props.auth.user?.role, inertiaPage.props.navBadges?.confirmations)"
                :label="t('section_tabs.orders.label')"
            />

            <Callout v-if="!enabled" tone="warning" :title="t('order_confirmations.off.title')" data-confirmations-off>
                {{ t('order_confirmations.off.body') }}
                <Link v-if="canEditSettings" href="/settings/order-confirmations" class="font-medium underline">{{
                    t('order_confirmations.off.action')
                }}</Link>
            </Callout>

            <div
                class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5 [&>*:last-child]:max-sm:col-span-2"
                role="tablist"
                :aria-label="title"
                data-confirmation-counters
            >
                <button
                    v-for="box in boxes"
                    :key="box.tab"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === box.tab"
                    class="min-w-0 rounded-lg border px-4 py-3 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    :class="
                        activeTab === box.tab
                            ? 'border-brand bg-brand-soft shadow-[inset_0_0_0_1px_hsl(var(--brand))]'
                            : 'border-line bg-card hover:border-line-strong hover:bg-surface-2'
                    "
                    :data-tab="box.tab"
                    :data-urgent="box.urgent || undefined"
                    @click="set({ tab: box.tab })"
                >
                    <span class="block truncate text-[12.5px] font-semibold" :class="activeTab === box.tab ? 'text-brand-ink' : 'text-fg-2'">{{
                        t(`order_confirmations.tabs.${box.tab}`)
                    }}</span>
                    <span class="block text-2xl font-bold tabular-nums leading-snug" :class="box.urgent ? 'text-danger' : 'text-foreground'">{{
                        n(box.value)
                    }}</span>
                </button>
            </div>

            <!-- The day filter and the day's numbers on one line (the call list ignores the day). -->
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <label class="flex items-center gap-2">
                    <span class="text-13 font-semibold text-fg-2">{{ t('order_confirmations.day') }}</span>
                    <DateInput :model-value="day" :max="today" @update:model-value="set({ date: $event && $event !== today ? $event : null })" />
                </label>
                <button
                    v-if="!isToday"
                    type="button"
                    class="inline-flex h-9 items-center rounded-md border border-line-strong bg-card px-3 text-13 font-semibold hover:bg-surface-2"
                    @click="set({ date: null })"
                >
                    {{ t('order_confirmations.back_to_today') }}
                </button>
                <p class="min-w-0 flex-1 text-xs tabular-nums text-fg-2" data-day-line>{{ dayLine }}</p>
            </div>
            <p v-if="allDays" class="text-xs text-fg-2" data-all-days>{{ t('order_confirmations.all_days_hint') }}</p>

            <div role="tabpanel" class="min-w-0">
                <DataTable
                    card-grid
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
                        <span class="inline-flex items-center gap-1" @click.stop>
                            <!-- The call itself first (mockup «اتصلي»): a tel: link on the phone, then what she said. -->
                            <a
                                v-if="row.needs_call && row.phone"
                                :href="`tel:${row.phone}`"
                                class="inline-flex h-[30px] items-center gap-1.5 rounded-md bg-brand px-2.5 text-13 font-semibold text-brand-on hover:bg-brand-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                :aria-label="t('order_confirmations.call.dial', { phone: row.phone })"
                                data-dial
                            >
                                <Phone class="size-3.5" aria-hidden="true" />{{ t('order_confirmations.call.dial_short') }}
                            </a>
                            <CallOutcomeActions v-if="row.needs_call" :confirmation-id="row.id" @recorded="onRecorded" />
                            <CallOutcomeActions v-else-if="row.can_record" menu :confirmation-id="row.id" @recorded="onRecorded" />
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
