<script setup lang="ts">
import PageHeader from '@/components/crm/PageHeader.vue';
import StatCard from '@/components/crm/StatCard.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatDate, formatDateTime } from '@/lib/format';
import type { ShopifyImportState, ShopifyReconcileResult } from '@/types/admin';
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2, CircleAlert, Download, LoaderCircle, RefreshCw } from 'lucide-vue-next';
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

const from = ref(props.result?.from ?? props.defaults.from);
const to = ref(props.result?.to ?? props.defaults.to);
const result = ref<ShopifyReconcileResult | null>(props.result);
const checking = ref(false);
const importing = ref(false);
const importState = ref<ShopifyImportState | null>(props.importState);

const ordersStage = computed(() => importState.value?.stages?.orders ?? null);
const importRunning = computed(() => ordersStage.value?.status === 'running');

async function check(): Promise<void> {
    checking.value = true;
    try {
        const { data } = await api.post<{ ok: boolean; result: ShopifyReconcileResult }>('/settings/shopify/reconcile', { from: from.value, to: to.value });
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
        const { data } = await api.get<{ integration: { import_state: ShopifyImportState | null } | null }>('/settings/shopify/status', { silent: true });
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
    return diff === 0 ? '✓' : `${diff > 0 ? '+' : '−'}${formatCount(Math.abs(diff), locale.value)}`;
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
    { key: 'payment_gateway', title: t('settings.shopify.reconcile.by_gateway'), rows: result.value?.breakdown.payment_gateway ?? [], label: (k: string | null) => k ?? t('settings.shopify.reconcile.unknown') },
    { key: 'province', title: t('settings.shopify.reconcile.by_province'), rows: result.value?.breakdown.province ?? [], label: (k: string | null) => k ?? t('settings.shopify.reconcile.unknown') },
    { key: 'shipment', title: t('settings.shopify.reconcile.by_shipment'), rows: result.value?.breakdown.shipment ?? [], label: shipmentLabel },
]);

const breadcrumbs = computed(() => [
    { title: t('settings.shopify.title'), href: '/settings/shopify' },
    { title: t('settings.shopify.reconcile.title'), href: '/settings/shopify/reconcile' },
]);
</script>

<template>
    <Head :title="t('settings.shopify.reconcile.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto grid w-full max-w-7xl gap-4 p-3 md:p-6">
            <PageHeader :title="t('settings.shopify.reconcile.title')" :description="t('settings.shopify.reconcile.description')" />

            <p v-if="!connected" class="rounded-lg bg-warning/10 p-3 text-xs">
                {{ t('settings.shopify.reconcile.not_connected') }}
                <Link href="/settings/shopify" class="font-medium underline">{{ t('settings.shopify.title') }}</Link>
            </p>

            <section class="flex flex-wrap items-end gap-2 rounded-lg bg-card p-4 text-xs shadow-card">
                <label class="grid gap-1">
                    <span class="text-muted-foreground">{{ t('settings.shopify.sync.from') }}</span>
                    <input v-model="from" type="date" dir="ltr" class="h-9 rounded-md border border-input bg-background px-2" :max="to" />
                </label>
                <label class="grid gap-1">
                    <span class="text-muted-foreground">{{ t('settings.shopify.sync.to') }}</span>
                    <input v-model="to" type="date" dir="ltr" class="h-9 rounded-md border border-input bg-background px-2" :min="from" />
                </label>
                <button
                    type="button"
                    class="inline-flex h-9 items-center gap-1.5 rounded-md bg-primary px-3 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                    :disabled="checking || !connected || !from || !to"
                    @click="check"
                >
                    <LoaderCircle v-if="checking" class="size-4 animate-spin" aria-hidden="true" />
                    <RefreshCw v-else class="size-4" aria-hidden="true" />
                    {{ checking ? t('settings.shopify.reconcile.checking') : t('settings.shopify.reconcile.check') }}
                </button>
                <p class="basis-full text-muted-foreground">{{ t('settings.shopify.reconcile.hint') }}</p>
            </section>

            <section v-if="importRunning" class="flex items-center gap-2 rounded-lg bg-primary/10 p-3 text-xs" role="status">
                <LoaderCircle class="size-4 animate-spin" aria-hidden="true" />
                {{
                    t('settings.shopify.reconcile.importing', {
                        processed: formatCount(ordersStage?.processed ?? 0, locale),
                    })
                }}
            </section>

            <template v-if="result">
                <section
                    class="flex flex-wrap items-center gap-2 rounded-lg p-3 text-sm font-medium"
                    :class="result.matched ? 'bg-success/10 text-success' : 'bg-destructive/10 text-destructive'"
                    role="status"
                >
                    <CheckCircle2 v-if="result.matched" class="size-5" aria-hidden="true" />
                    <CircleAlert v-else class="size-5" aria-hidden="true" />
                    <span v-if="result.matched">{{ t('settings.shopify.reconcile.matched', { n: formatCount(result.totals.shopify, locale) }) }}</span>
                    <span v-else-if="mismatchedDays > 0">{{ t('settings.shopify.reconcile.not_matched', { days: formatCount(mismatchedDays, locale) }) }}</span>
                    <span v-else>{{ t('settings.shopify.reconcile.statuses_only', { n: formatCount(result.totals.shopify, locale) }) }}</span>
                    <span class="ms-auto text-2xs font-normal text-muted-foreground">
                        {{ t('settings.shopify.reconcile.checked_at', { at: formatDateTime(result.checked_at, locale) }) }}
                    </span>
                    <button
                        v-if="!result.matched"
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        :disabled="importing || importRunning"
                        @click="importRange"
                    >
                        <LoaderCircle v-if="importing" class="size-3.5 animate-spin" aria-hidden="true" />
                        <Download v-else class="size-3.5" aria-hidden="true" />
                        {{ t('settings.shopify.reconcile.bring', { from: formatDate(result.from, locale), to: formatDate(result.to, locale) }) }}
                    </button>
                </section>

                <div class="grid gap-3 sm:grid-cols-3">
                    <StatCard :label="t('settings.shopify.reconcile.in_shopify')" :value="result.totals.shopify" />
                    <StatCard :label="t('settings.shopify.reconcile.in_crm')" :value="result.totals.crm" :tone="result.totals.diff === 0 ? 'positive' : 'warning'" />
                    <StatCard :label="t('settings.shopify.reconcile.difference')" :value="diffLabel(result.totals.diff)" :tone="result.totals.diff === 0 ? 'positive' : 'negative'" />
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <section class="grid content-start gap-2 rounded-lg bg-card p-4 text-xs shadow-card">
                        <h2 class="text-sm font-semibold">{{ t('settings.shopify.reconcile.per_day') }}</h2>
                        <div class="max-h-[32rem] overflow-auto">
                            <table class="w-full tabular-nums">
                                <thead class="sticky top-0 bg-card text-muted-foreground">
                                    <tr>
                                        <th class="py-1.5 text-start font-medium">{{ t('settings.shopify.reconcile.day') }}</th>
                                        <th class="py-1.5 text-end font-medium">Shopify</th>
                                        <th class="py-1.5 text-end font-medium">{{ t('settings.shopify.reconcile.crm') }}</th>
                                        <th class="py-1.5 text-end font-medium">{{ t('settings.shopify.reconcile.diff') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="day in result.days" :key="day.date" class="border-t border-border" :class="{ 'bg-destructive/5': day.diff !== 0 }">
                                        <td class="py-1.5">{{ formatDate(day.date, locale) }}</td>
                                        <td class="py-1.5 text-end">{{ formatCount(day.shopify, locale) }}</td>
                                        <td class="py-1.5 text-end">{{ formatCount(day.crm, locale) }}</td>
                                        <td class="py-1.5 text-end font-medium" :class="day.diff === 0 ? 'text-success' : 'text-destructive'">{{ diffLabel(day.diff) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="grid content-start gap-3 rounded-lg bg-card p-4 text-xs shadow-card">
                        <h2 class="text-sm font-semibold">{{ t('settings.shopify.reconcile.per_status') }}</h2>
                        <p class="text-muted-foreground">{{ t('settings.shopify.reconcile.per_status_hint') }}</p>
                        <div v-for="(rows, group) in statusGroups" :key="group" class="grid gap-1">
                            <h3 class="font-medium">{{ t(`settings.shopify.reconcile.groups.${group}`) }}</h3>
                            <table class="w-full tabular-nums">
                                <tbody>
                                    <tr v-for="row in rows" :key="row.key" class="border-t border-border">
                                        <td class="py-1.5">{{ statusLabel(row.group, row.key) }}</td>
                                        <td class="py-1.5 text-end">{{ formatCount(row.shopify, locale) }}</td>
                                        <td class="py-1.5 text-end">{{ formatCount(row.crm, locale) }}</td>
                                        <td class="py-1.5 text-end font-medium" :class="row.diff === 0 ? 'text-success' : 'text-destructive'">{{ diffLabel(row.diff) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="result.missing.length" class="grid gap-1">
                            <h3 class="font-medium text-destructive">
                                {{ t('settings.shopify.reconcile.missing_title', { n: formatCount(result.missing.length, locale) }) }}
                            </h3>
                            <p class="flex flex-wrap gap-1" dir="ltr">
                                <code v-for="m in result.missing" :key="m.id" class="rounded bg-muted px-1.5 py-0.5">{{ m.name ?? m.id }}</code>
                            </p>
                        </div>
                        <div v-if="result.extra.length" class="grid gap-1">
                            <h3 class="font-medium">{{ t('settings.shopify.reconcile.extra_title', { n: formatCount(result.extra.length, locale) }) }}</h3>
                            <p class="flex flex-wrap gap-1" dir="ltr">
                                <code v-for="m in result.extra" :key="m.id" class="rounded bg-muted px-1.5 py-0.5">{{ m.name ?? m.id }}</code>
                            </p>
                        </div>
                    </section>
                </div>

                <section class="grid gap-3 lg:grid-cols-3">
                    <div v-for="b in breakdowns" :key="b.key" class="grid content-start gap-2 rounded-lg bg-card p-4 text-xs shadow-card">
                        <h2 class="text-sm font-semibold">{{ b.title }}</h2>
                        <ul class="grid max-h-80 gap-1 overflow-auto">
                            <li v-for="row in b.rows" :key="row.key ?? '—'" class="flex justify-between gap-2 border-t border-border py-1.5">
                                <span class="truncate">{{ b.label(row.key) }}</span>
                                <span class="tabular-nums font-medium">{{ formatCount(row.count, locale) }}</span>
                            </li>
                        </ul>
                    </div>
                </section>
            </template>

            <p v-else-if="connected" class="rounded-lg bg-card p-6 text-center text-xs text-muted-foreground shadow-card">
                {{ t('settings.shopify.reconcile.empty') }}
            </p>
        </div>
    </AppLayout>
</template>
