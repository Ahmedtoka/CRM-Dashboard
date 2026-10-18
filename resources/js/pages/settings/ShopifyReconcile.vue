<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import DateInput from '@/components/crm/DateInput.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import SettingsShell from '@/components/crm/settings/SettingsShell.vue';
import Ledger, { type LedgerFigure } from '@/components/today/Ledger.vue';
import { Button } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useSettingsBreadcrumbs } from '@/composables/useSettingsBreadcrumbs';
import { useToast } from '@/composables/useToast';
import { useUrlFilters } from '@/composables/useUrlFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatDate, formatDateTime } from '@/lib/format';
import { sectionTabs } from '@/lib/sectionTabs';
import type { ShopifyImportState, ShopifyReconcileResult } from '@/types/admin';
import { Head, Link } from '@inertiajs/vue3';
import { Check, CheckCircle2, CircleAlert, Download, LoaderCircle, ScanSearch } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps<{
    connected: boolean;
    result: ShopifyReconcileResult | null;
    defaults: { from: string; to: string };
    importState: ShopifyImportState | null;
}>();

const { t, locale } = useI18n();
const api = useApi();
const toast = useToast();

// The range lives in the URL (a reload or a shared link keeps it); with none there, the last check's range.
const { filters: range, set: setRange } = useUrlFilters({ from: props.defaults.from as string, to: props.defaults.to as string });
if (props.result && !new URLSearchParams(window.location.search).has('from')) range.value = { from: props.result.from, to: props.result.to };
const from = computed({ get: () => range.value.from, set: (value: string) => setRange({ from: value }) });
const to = computed({ get: () => range.value.to, set: (value: string) => setRange({ to: value }) });
const result = ref<ShopifyReconcileResult | null>(props.result);
const checking = ref(false);
const importing = ref(false);
const importState = ref<ShopifyImportState | null>(props.importState);

const ordersStage = computed(() => importState.value?.stages?.orders ?? null);
const importRunning = computed(() => ordersStage.value?.status === 'running');

async function check(): Promise<void> {
    checking.value = true;
    try {
        const { data } = await api.post<{ ok: boolean; result: ShopifyReconcileResult }>('/settings/shopify/reconcile', {
            from: from.value,
            to: to.value,
        });
        result.value = data.result;
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        checking.value = false;
    }
}

// "Bring them": the same orders range import as the Shopify page (one Bulk Operation).
async function importRange(): Promise<void> {
    if (!result.value) return;
    importing.value = true;
    try {
        await api.post('/settings/shopify/sync', { resource: 'orders', from: result.value.from, to: result.value.to });
        toast.push(t('settings.shopify.reconcile.import_queued'));
        watchImport();
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        importing.value = false;
    }
}

let timer: ReturnType<typeof setInterval> | null = null;

function watchImport(): void {
    if (timer) return;
    timer = setInterval(async () => {
        const { data } = await api.get<{ integration: { import_state: ShopifyImportState | null } | null }>('/settings/shopify/status', {
            silent: true,
        });
        importState.value = data.integration?.import_state ?? null;
        if (!importRunning.value && timer) {
            clearInterval(timer);
            timer = null;
            if (ordersStage.value?.status === 'completed') await check();
        }
    }, 4000);
}

if (importRunning.value) watchImport();
onBeforeUnmount(() => timer && clearInterval(timer));

const mismatchedDays = computed(() => result.value?.days.filter((d) => d.diff !== 0).length ?? 0);
const statusGroups = computed(() => {
    const groups: Record<string, ShopifyReconcileResult['statuses']> = { financial: [], fulfillment: [], state: [] };
    for (const row of result.value?.statuses ?? []) {
        if (row.shopify > 0 || row.crm > 0) groups[row.group].push(row);
    }
    return groups;
});

function diffLabel(diff: number): string {
    return diff === 0 ? t('settings.shopify.reconcile.same') : `${diff > 0 ? '+' : '−'}${formatCount(Math.abs(diff), locale.value)}`;
}

function statusLabel(group: string, key: string): string {
    return t(`settings.shopify.reconcile.values.${group}.${key}`);
}

function shipmentLabel(key: string | null): string {
    if (!key) return t('settings.shopify.reconcile.values.shipment.none');
    const label = t(`settings.shopify.reconcile.values.shipment.${key}`);
    return label.startsWith('settings.') ? key : label;
}

const breakdowns = computed(() => [
    {
        key: 'payment_gateway',
        title: t('settings.shopify.reconcile.by_gateway'),
        rows: result.value?.breakdown.payment_gateway ?? [],
        label: (k: string | null) => k ?? t('settings.shopify.reconcile.unknown'),
    },
    {
        key: 'province',
        title: t('settings.shopify.reconcile.by_province'),
        rows: result.value?.breakdown.province ?? [],
        label: (k: string | null) => k ?? t('settings.shopify.reconcile.unknown'),
    },
    { key: 'shipment', title: t('settings.shopify.reconcile.by_shipment'), rows: result.value?.breakdown.shipment ?? [], label: shipmentLabel },
]);

const dayRows = computed(() => (result.value?.days ?? []).map((day) => ({ ...day, id: day.date })));
const dayColumns = computed<Column[]>(() => [
    { key: 'date', label: t('settings.shopify.reconcile.day'), primary: true },
    { key: 'shopify', label: 'Shopify', numeric: true },
    { key: 'crm', label: t('settings.shopify.reconcile.crm'), numeric: true },
    { key: 'diff', label: t('settings.shopify.reconcile.diff'), numeric: true },
]);
const statusColumns = computed<Column[]>(() => [
    { key: 'key', label: t('settings.shopify.reconcile.per_status'), primary: true },
    { key: 'shopify', label: 'Shopify', numeric: true },
    { key: 'crm', label: t('settings.shopify.reconcile.crm'), numeric: true },
    { key: 'diff', label: t('settings.shopify.reconcile.diff'), numeric: true },
]);

/** Shopify / ours / the difference: 0 reads «متطابق» in green, anything else says which side is short, in red. */
const figures = computed<LedgerFigure[]>(() => {
    if (!result.value) return [];
    const { shopify, crm, diff } = result.value.totals;
    return [
        { key: 'shopify', label: t('settings.shopify.reconcile.in_shopify'), value: formatCount(shopify, locale.value) },
        { key: 'crm', label: t('settings.shopify.reconcile.in_crm'), value: formatCount(crm, locale.value) },
        {
            key: 'diff',
            label: t('settings.shopify.reconcile.difference'),
            value: formatCount(Math.abs(diff), locale.value),
            note:
                diff === 0
                    ? t('settings.shopify.reconcile.same')
                    : diff < 0
                      ? t('settings.shopify.reconcile.short_here')
                      : t('settings.shopify.reconcile.extra_here'),
            tone: diff === 0 ? 'up' : 'down',
        },
    ];
});

const title = computed(() => t('settings.shopify.reconcile.title'));
const breadcrumbs = useSettingsBreadcrumbs(() => title.value);
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsShell
            :title="title"
            :description="t('settings.shopify.reconcile.description')"
            :tabs="sectionTabs.shopify(t)"
            :tabs-label="t('section_tabs.shopify.label')"
            :freshness="result?.checked_at ?? null"
        >
            <p v-if="!connected" role="alert" class="rounded-lg border border-warn/40 bg-warn-soft px-4 py-3 text-13">
                {{ t('settings.shopify.reconcile.not_connected') }}
                <Link href="/settings/shopify" class="font-semibold underline underline-offset-2">{{ t('settings.shopify.title') }}</Link>
            </p>

            <Ledger v-if="result" :figures="figures" :label="t('settings.shopify.reconcile.title')" />

            <section class="grid gap-3 rounded-lg border border-line bg-card p-4" :aria-label="t('settings.shopify.reconcile.check')">
                <div class="flex flex-wrap items-end gap-3">
                    <label class="grid gap-1 text-13">
                        <span class="font-semibold">{{ t('settings.shopify.sync.from') }}</span>
                        <DateInput v-model="from" class="h-10 rounded-md border border-line-strong bg-card px-2 sm:h-9" :max="to" />
                    </label>
                    <label class="grid gap-1 text-13">
                        <span class="font-semibold">{{ t('settings.shopify.sync.to') }}</span>
                        <DateInput v-model="to" class="h-10 rounded-md border border-line-strong bg-card px-2 sm:h-9" :min="from" />
                    </label>
                    <Button type="button" class="max-sm:h-10" :disabled="checking || !connected || !from || !to" :loading="checking" @click="check">
                        <ScanSearch aria-hidden="true" />
                        {{ checking ? t('settings.shopify.reconcile.checking') : t('settings.shopify.reconcile.check') }}
                    </Button>
                    <span v-if="result" class="text-xs text-fg-3">{{
                        t('settings.shopify.reconcile.checked_at', { at: formatDateTime(result.checked_at, locale) })
                    }}</span>
                </div>
                <p class="text-13 text-fg-2">{{ t('settings.shopify.reconcile.hint') }}</p>
            </section>

            <section v-if="importRunning" class="flex items-center gap-2 rounded-lg bg-info-soft px-4 py-3 text-13 text-info" role="status">
                <LoaderCircle class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                {{ t('settings.shopify.reconcile.importing', { processed: formatCount(ordersStage?.processed ?? 0, locale) }) }}
            </section>

            <template v-if="result">
                <section
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg px-4 py-3 text-sm font-semibold"
                    :class="result.matched ? 'bg-ok-soft text-ok' : 'bg-danger-soft text-danger'"
                    role="status"
                >
                    <CheckCircle2 v-if="result.matched" class="size-5 shrink-0" aria-hidden="true" />
                    <CircleAlert v-else class="size-5 shrink-0" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <template v-if="result.matched">{{
                            t('settings.shopify.reconcile.matched', { n: formatCount(result.totals.shopify, locale) })
                        }}</template>
                        <template v-else-if="mismatchedDays > 0">{{
                            t('settings.shopify.reconcile.not_matched', { days: formatCount(mismatchedDays, locale) })
                        }}</template>
                        <template v-else>{{
                            t('settings.shopify.reconcile.statuses_only', { n: formatCount(result.totals.shopify, locale) })
                        }}</template>
                    </span>
                    <Button
                        v-if="!result.matched"
                        size="sm"
                        variant="outline"
                        class="max-sm:h-10"
                        type="button"
                        :disabled="importing || importRunning"
                        :loading="importing"
                        @click="importRange"
                    >
                        <Download aria-hidden="true" />
                        {{ t('settings.shopify.reconcile.bring', { from: formatDate(result.from, locale), to: formatDate(result.to, locale) }) }}
                    </Button>
                </section>

                <div class="grid items-start gap-5 lg:grid-cols-2">
                    <section class="grid gap-2">
                        <h2 class="text-base font-bold">{{ t('settings.shopify.reconcile.per_day') }}</h2>
                        <DataTable
                            table-id="reconcile-days"
                            :columns="dayColumns"
                            :rows="dayRows"
                            :row-class="(day) => (day.diff !== 0 ? 'bg-danger-soft/50' : undefined)"
                            mobile="scroll"
                            :caption="t('settings.shopify.reconcile.per_day')"
                        >
                            <template #cell-date="{ row }">{{ formatDate(row.date, locale) }}</template>
                            <template #cell-diff="{ row }">
                                <span class="font-semibold tabular-nums" :class="row.diff === 0 ? 'text-ok' : 'text-danger'">
                                    <template v-if="row.diff === 0"
                                        ><Check class="inline size-3.5" aria-hidden="true" /><span class="sr-only">{{
                                            t('settings.shopify.reconcile.same')
                                        }}</span></template
                                    >
                                    <template v-else>{{ diffLabel(row.diff) }}</template>
                                </span>
                            </template>
                            <template #totals>
                                <tr>
                                    <td class="px-3 py-2">{{ t('table.totals') }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ formatCount(result.totals.shopify, locale) }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ formatCount(result.totals.crm, locale) }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums" :class="result.totals.diff === 0 ? 'text-ok' : 'text-danger'">
                                        {{ diffLabel(result.totals.diff) }}
                                    </td>
                                </tr>
                            </template>
                        </DataTable>
                    </section>

                    <section class="grid gap-3">
                        <div>
                            <h2 class="text-base font-bold">{{ t('settings.shopify.reconcile.per_status') }}</h2>
                            <p class="text-13 text-fg-2">{{ t('settings.shopify.reconcile.per_status_hint') }}</p>
                        </div>
                        <div v-for="(rows, group) in statusGroups" :key="group" class="grid gap-1.5">
                            <h3 class="text-sm font-semibold">{{ t(`settings.shopify.reconcile.groups.${group}`) }}</h3>
                            <DataTable
                                table-id="reconcile-status"
                                :columns="statusColumns"
                                :rows="rows.map((row) => ({ ...row, id: row.key }))"
                                mobile="scroll"
                                :caption="t(`settings.shopify.reconcile.groups.${group}`)"
                            >
                                <template #cell-key="{ row }">{{ statusLabel(row.group, row.key) }}</template>
                                <template #cell-diff="{ row }">
                                    <span class="font-semibold tabular-nums" :class="row.diff === 0 ? 'text-ok' : 'text-danger'">
                                        <template v-if="row.diff === 0"
                                            ><Check class="inline size-3.5" aria-hidden="true" /><span class="sr-only">{{
                                                t('settings.shopify.reconcile.same')
                                            }}</span></template
                                        >
                                        <template v-else>{{ diffLabel(row.diff) }}</template>
                                    </span>
                                </template>
                            </DataTable>
                        </div>

                        <div v-if="result.missing.length" class="grid gap-1.5 rounded-lg border border-line bg-card p-4">
                            <h3 class="text-sm font-semibold text-danger">
                                {{ t('settings.shopify.reconcile.missing_title', { n: formatCount(result.missing.length, locale) }) }}
                            </h3>
                            <p class="flex flex-wrap gap-1" dir="ltr">
                                <code v-for="m in result.missing" :key="m.id" class="rounded-sm bg-sunken px-1.5 py-0.5 text-xs">{{
                                    m.name ?? m.id
                                }}</code>
                            </p>
                        </div>
                        <div v-if="result.extra.length" class="grid gap-1.5 rounded-lg border border-line bg-card p-4">
                            <h3 class="text-sm font-semibold">
                                {{ t('settings.shopify.reconcile.extra_title', { n: formatCount(result.extra.length, locale) }) }}
                            </h3>
                            <p class="flex flex-wrap gap-1" dir="ltr">
                                <code v-for="m in result.extra" :key="m.id" class="rounded-sm bg-sunken px-1.5 py-0.5 text-xs">{{
                                    m.name ?? m.id
                                }}</code>
                            </p>
                        </div>
                    </section>
                </div>

                <div class="grid items-start gap-5 lg:grid-cols-3">
                    <section
                        v-for="b in breakdowns"
                        :key="b.key"
                        class="grid content-start gap-2 rounded-lg border border-line bg-card p-4"
                        :aria-label="b.title"
                    >
                        <h2 class="text-base font-bold">{{ b.title }}</h2>
                        <ul class="grid max-h-80 overflow-auto text-13">
                            <li
                                v-for="row in b.rows"
                                :key="row.key ?? '—'"
                                class="flex justify-between gap-2 border-t border-line py-2 first:border-t-0"
                            >
                                <span class="truncate">{{ b.label(row.key) }}</span>
                                <span class="font-semibold tabular-nums">{{ formatCount(row.count, locale) }}</span>
                            </li>
                        </ul>
                    </section>
                </div>
            </template>

            <div v-else-if="connected" class="rounded-lg border border-line bg-card">
                <EmptyState :icon="ScanSearch" :title="t('settings.shopify.reconcile.empty')" />
            </div>
        </SettingsShell>
    </AppLayout>
</template>
