<script setup lang="ts">
/** Ads Hub — المزامنة: what the ad syncs are doing now, what waits in the queue, the recent log and the schedule. */
import PlatformChip from '@/components/ads/PlatformChip.vue';
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import RelativeTime from '@/components/crm/RelativeTime.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { useNow } from '@/composables/useNow';
import { useVisiblePoll } from '@/composables/useVisiblePoll';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatDuration } from '@/lib/format';
import type { AdsSyncProps, AdsSyncRunRow } from '@/types/ads';
import { Head, router } from '@inertiajs/vue3';
import { CalendarClock, Hourglass, LoaderCircle, RefreshCw } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<AdsSyncProps>();

const { t, locale } = useI18n();
const clock = useNow();

// Only the live block is refreshed every 5 s, and only while the tab is visible.
useVisiblePoll(() => router.reload({ only: ['now'], async: true }), 5000);

const KINDS = ['recent', 'backfill', 'accounts'];
const kindLabel = (kind: string | null) => (kind && KINDS.includes(kind) ? t(`ads.sync.kind.${kind}`) : (kind ?? '—'));
const TRIGGERS = ['schedule', 'manual', 'backfill', 'setup'];
const triggerLabel = (trigger: string | null) => (trigger && TRIGGERS.includes(trigger) ? t(`ads.sync.trigger.${trigger}`) : '—');
const triggerTone = (trigger: string | null) => (trigger === 'manual' ? 'info' : trigger === 'schedule' ? 'neutral' : 'warning');

/** How long a running run has been going; ticks with the shared clock between the 5 s polls. */
const elapsed = (r: AdsSyncRunRow) => (r.started_at ? formatDuration(Math.max(0, clock.value - new Date(r.started_at).getTime()), locale.value) : '—');
const duration = (r: AdsSyncRunRow) => (r.seconds === null ? '—' : formatDuration(r.seconds * 1000, locale.value));

const statusTone = { running: 'info', ok: 'positive', error: 'negative' } as const;

function setFilter(key: 'account' | 'status' | 'trigger', value: string): void {
    const next: Record<string, unknown> = { ...props.filters, [key]: value === '' ? null : value };
    const query = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== '')) as Record<string, string>;
    router.get('/ads/sync', query, { preserveScroll: true, preserveState: true, replace: true });
}

const columns = computed<Column[]>(() => [
    { key: 'started_at', label: t('ads.sync.col_started'), primary: true },
    { key: 'account', label: t('ads.sync.col_account') },
    { key: 'kind', label: t('ads.sync.col_kind'), hideOnMobile: true },
    { key: 'status', label: t('ads.sync.col_status') },
    { key: 'trigger', label: t('ads.sync.col_trigger') },
    { key: 'user', label: t('ads.sync.col_user'), hideOnMobile: true },
    { key: 'range', label: t('ads.sync.col_range'), hideOnMobile: true },
    { key: 'counts', label: t('ads.sync.col_counts'), align: 'end', hideOnMobile: true },
    { key: 'seconds', label: t('ads.sync.col_duration'), align: 'end', hideOnMobile: true },
    { key: 'error', label: t('ads.sync.col_error'), hideOnMobile: true },
]);

const selectClass = 'h-8 min-w-32 rounded-md border border-input bg-card px-2 text-xs';
const breadcrumbs = computed(() => [
    { title: t('nav.ads'), href: '/ads' },
    { title: t('nav.ads_sync'), href: '/ads/sync' },
]);
const count = (v: number | null) => (v === null ? '—' : formatCount(v, locale.value));
</script>

<template>
    <Head :title="t('ads.sync.title')" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-6 p-4">
            <PageHeader :title="t('ads.sync.title')" :description="t('ads.sync.hint')" />

            <!-- Now: running + waiting -->
            <section class="space-y-2" aria-labelledby="sync-now">
                <h2 id="sync-now" class="text-sm font-semibold">{{ t('ads.sync.now') }}</h2>
                <ul v-if="now.running.length || now.waiting.length" class="divide-y divide-border rounded-lg border border-border bg-card">
                    <li v-for="r in now.running" :key="`run-${r.id}`" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5 text-sm">
                        <LoaderCircle class="size-4 shrink-0 animate-spin text-primary" aria-hidden="true" />
                        <span class="font-medium">{{ r.account ?? '—' }}</span>
                        <PlatformChip :platform="r.platform" size="xs" />
                        <span class="text-xs text-muted-foreground">{{ kindLabel(r.kind) }}</span>
                        <StatusChip :label="triggerLabel(r.trigger)" :tone="triggerTone(r.trigger)" />
                        <span v-if="r.user" class="text-xs text-muted-foreground">{{ r.user }}</span>
                        <span class="ms-auto text-xs tabular-nums text-muted-foreground">{{ t('ads.sync.elapsed', { time: elapsed(r) }) }}</span>
                    </li>
                    <li v-for="(w, i) in now.waiting" :key="`wait-${i}`" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5 text-sm">
                        <Hourglass class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <span class="font-medium">{{ w.account ?? w.job }}</span>
                        <span class="text-xs text-muted-foreground">{{ kindLabel(w.kind) }}</span>
                        <StatusChip :label="triggerLabel(w.trigger)" :tone="triggerTone(w.trigger)" />
                        <StatusChip v-if="w.attempts > 0" :label="t('ads.sync.attempt', { n: formatCount(w.attempts, locale) })" tone="warning" />
                        <span class="ms-auto flex items-center gap-1 text-xs text-muted-foreground">
                            <template v-if="w.available_at">{{ t('ads.sync.available') }} <RelativeTime :iso="w.available_at" /></template>
                            <template v-else>{{ t('ads.sync.ready') }}</template>
                        </span>
                    </li>
                </ul>
                <div v-else class="flex rounded-lg border border-border bg-card">
                    <EmptyState :icon="RefreshCw" :title="t('ads.sync.idle_title')" :body="t('ads.sync.idle_body')" />
                </div>
                <p v-if="!now.supported" class="text-2xs text-muted-foreground">{{ t('ads.sync.queue_unsupported') }}</p>
            </section>

            <!-- Log -->
            <section class="space-y-2" aria-labelledby="sync-log">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 id="sync-log" class="me-auto text-sm font-semibold">{{ t('ads.sync.log') }}</h2>
                    <label class="sr-only" for="f-account">{{ t('ads.sync.col_account') }}</label>
                    <select id="f-account" :class="selectClass" :value="filters.account ?? ''" @change="setFilter('account', ($event.target as HTMLSelectElement).value)">
                        <option value="">{{ t('ads.sync.all_accounts') }}</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                    <label class="sr-only" for="f-status">{{ t('ads.sync.col_status') }}</label>
                    <select id="f-status" :class="selectClass" :value="filters.status ?? ''" @change="setFilter('status', ($event.target as HTMLSelectElement).value)">
                        <option value="">{{ t('ads.sync.all_statuses') }}</option>
                        <option v-for="s in ['running', 'ok', 'error']" :key="s" :value="s">{{ t(`ads.sync.status.${s}`) }}</option>
                    </select>
                    <label class="sr-only" for="f-trigger">{{ t('ads.sync.col_trigger') }}</label>
                    <select id="f-trigger" :class="selectClass" :value="filters.trigger ?? ''" @change="setFilter('trigger', ($event.target as HTMLSelectElement).value)">
                        <option value="">{{ t('ads.sync.all_triggers') }}</option>
                        <option v-for="g in TRIGGERS" :key="g" :value="g">{{ triggerLabel(g) }}</option>
                    </select>
                </div>
                <DataTable table-id="ads-sync" :columns="columns" :rows="runs" :empty="t('ads.sync.log_empty')" :empty-icon="RefreshCw" :caption="t('ads.sync.log')">
                    <template #cell-started_at="{ row }"><RelativeTime :iso="row.started_at" mode="stamp" /></template>
                    <template #cell-account="{ row }">
                        <span class="inline-flex items-center gap-1.5">{{ row.account ?? '—' }}<PlatformChip :platform="row.platform" size="xs" /></span>
                    </template>
                    <template #cell-kind="{ row }">{{ kindLabel(row.kind) }}</template>
                    <template #cell-status="{ row }"><StatusChip :label="t(`ads.sync.status.${row.status}`)" :tone="statusTone[row.status]" dot /></template>
                    <template #cell-trigger="{ row }"><StatusChip :label="triggerLabel(row.trigger)" :tone="triggerTone(row.trigger)" /></template>
                    <template #cell-user="{ row }">{{ row.user ?? '—' }}</template>
                    <template #cell-range="{ row }">
                        <span v-if="row.from" class="tabular-nums" dir="ltr">{{ row.from }} → {{ row.to }}</span><span v-else>—</span>
                    </template>
                    <template #cell-counts="{ row }"><span class="tabular-nums">{{ count(row.ads_count) }} / {{ count(row.rows_count) }}</span></template>
                    <template #cell-seconds="{ row }">{{ duration(row) }}</template>
                    <template #cell-error="{ row }">
                        <span v-if="row.error" class="line-clamp-2 max-w-xs text-xs text-destructive" :title="row.error">{{ row.error }}</span><span v-else>—</span>
                    </template>
                </DataTable>
            </section>

            <!-- Schedule -->
            <section class="space-y-2" aria-labelledby="sync-schedule">
                <h2 id="sync-schedule" class="text-sm font-semibold">{{ t('ads.sync.schedule') }}</h2>
                <ul v-if="schedule.length" class="divide-y divide-border rounded-lg border border-border bg-card">
                    <li v-for="s in schedule" :key="s.command" class="flex flex-wrap items-center gap-3 px-3 py-2.5 text-sm">
                        <CalendarClock class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <code class="text-xs" dir="ltr">{{ s.command }}</code>
                        <span class="ms-auto flex items-center gap-1 text-xs text-muted-foreground">{{ t('ads.sync.next_due') }} <RelativeTime :iso="s.next_due" mode="datetime" /></span>
                    </li>
                </ul>
                <div v-else class="flex rounded-lg border border-border bg-card">
                    <EmptyState :icon="CalendarClock" :title="t('ads.sync.schedule_empty')" />
                </div>
            </section>
        </div>
    </AppLayout>
</template>
