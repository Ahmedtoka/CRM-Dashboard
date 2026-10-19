<script setup lang="ts">
import BarChart from '@/components/crm/BarChart.vue';
import DataTable from '@/components/crm/DataTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import SectionTabs from '@/components/crm/SectionTabs.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import Ledger, { type LedgerFigure } from '@/components/today/Ledger.vue';
import { Button } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { formatNumber } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatDate, formatDateTime, formatUsd } from '@/lib/format';
import { sectionTabs } from '@/lib/sectionTabs';
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, Wallet } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Report {
    spend: { today: number; week: number; month: number; avg_day_7: number };
    daily: { date: string; usd: number }[];
    by_model: { model: string | null; tier: string | null; runs: number; usd: number }[];
    conversations: { count: number; usd: number; per_conversation: number; per_1000: number };
    cache: { read: number; input: number; share: number };
    balance:
        | { set: false }
        | {
              set: true;
              entered_usd: number;
              entered_at: string;
              spent_since: number;
              left_usd: number;
              days_left: number | null;
              runs_out_on: string | null;
              warn: boolean;
          };
    sonnet: { spent_today: number; cap: number; hit: boolean };
    scenarios: {
        month_turns: number;
        legacy: { per_turn: number; month: number; measured: boolean };
        hybrid: { per_turn: number; month: number; measured: boolean };
    };
    console_today: number;
}

const props = defineProps<{ report: Report; canEditBalance: boolean; nowLocal: string }>();

const { t, locale } = useI18n();
const api = useApi();
const toast = useToast();
const usd = (v: number) => formatUsd(v, locale.value);
const n = (v: number) => formatCount(v, locale.value);

const figures = computed<LedgerFigure[]>(() => [
    {
        key: 'today',
        label: t('bot_brain.costs.today'),
        value: usd(props.report.spend.today),
        note: props.report.console_today > 0 ? t('bot_brain.costs.console_line', { usd: usd(props.report.console_today) }) : null,
    },
    { key: 'week', label: t('bot_brain.costs.week'), value: usd(props.report.spend.week) },
    { key: 'month', label: t('bot_brain.costs.month'), value: usd(props.report.spend.month) },
    { key: 'avg', label: t('bot_brain.costs.avg'), value: usd(props.report.spend.avg_day_7), note: t('bot_brain.costs.avg_note') },
]);

const daily = computed(() => props.report.daily.map((d) => ({ key: d.date, label: formatDate(d.date, locale.value), value: d.usd })));

const modelRows = computed(() => props.report.by_model.map((r, i) => ({ ...r, id: i })));
const modelColumns = computed(() => [
    { key: 'model', label: t('bot_brain.costs.model'), primary: true },
    { key: 'tier', label: t('bot_brain.costs.tier') },
    { key: 'runs', label: t('bot_brain.costs.runs'), align: 'end' as const },
    { key: 'usd', label: t('bot_brain.costs.usd'), align: 'end' as const },
]);
const tierLabel = (tier: string | null) => (tier === 'shadow' ? t('bot_brain.costs.shadow') : tier ? t(`bot_brain.trace.tier.${tier}`) : '—');

const balance = computed(() => props.report.balance);
const amount = ref<string>(balance.value.set ? String(balance.value.entered_usd) : '');
// Cairo wall-clock «YYYY-MM-DDTHH:mm» from the server; it reads the value back as Cairo time.
const readAt = ref<string>(props.nowLocal);
const saving = ref(false);
const formError = ref<string | null>(null);

async function saveBalance(clear = false): Promise<void> {
    saving.value = true;
    formError.value = null;
    try {
        await api.put(
            '/settings/bot-balance',
            clear
                ? { balance_usd: null }
                : { balance_usd: amount.value === '' ? null : Number(amount.value), balance_at: readAt.value.replace('T', ' ') },
        );
        toast.push(t('bot_brain.costs.saved'));
        router.reload({ only: ['report'] });
        if (clear) amount.value = '';
    } catch (e) {
        formError.value = apiErrorMessage(e, t('bot_brain.costs.save_failed'));
    } finally {
        saving.value = false;
    }
}

const pct = (v: number) => formatNumber(locale.value, v, { style: 'percent', maximumFractionDigits: 0 });
const daysText = computed(() =>
    balance.value.set && balance.value.days_left !== null ? formatNumber(locale.value, balance.value.days_left, { maximumFractionDigits: 0 }) : null,
);
</script>

<template>
    <Head :title="t('bot_brain.costs.title')" />

    <AppLayout
        :breadcrumbs="[
            { title: t('nav.bot'), href: '/bot' },
            { title: t('bot_brain.costs.title'), href: '/bot/costs' },
        ]"
    >
        <div class="mx-auto w-full max-w-6xl space-y-4 p-3 md:p-6">
            <PageHeader
                :title="t('bot_brain.costs.title')"
                :description="t('bot_brain.costs.hint')"
                :breadcrumbs="[{ label: t('nav.bot'), href: '/bot' }, { label: t('bot_brain.costs.title') }]"
            />
            <SectionTabs :tabs="sectionTabs.botBrain(t)" :label="t('bot_brain.nav.strip')" />

            <div class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                <!-- The question the owner opens this page with: how long the balance lasts. -->
                <section
                    class="flex flex-col rounded-lg border bg-card"
                    :class="balance.set && balance.warn ? 'border-danger/40' : 'border-line'"
                    aria-labelledby="balance-title"
                    data-testid="balance"
                >
                    <header class="flex items-center gap-2 border-b border-line px-4 py-3">
                        <Wallet class="size-4 text-fg-3" aria-hidden="true" />
                        <h2 id="balance-title" class="text-base font-bold">{{ t('bot_brain.costs.balance_title') }}</h2>
                    </header>
                    <div class="flex-1 space-y-2 px-4 py-4">
                        <template v-if="balance.set">
                            <p
                                v-if="daysText"
                                class="text-2xl font-bold leading-tight"
                                :class="balance.warn && 'text-danger'"
                                data-testid="days-left"
                            >
                                {{ t('bot_brain.costs.days_left', { n: daysText }) }}
                            </p>
                            <p v-else class="text-base font-semibold">{{ t('bot_brain.costs.no_spend') }}</p>
                            <p v-if="balance.runs_out_on" class="text-13 text-fg-2">
                                {{ t('bot_brain.costs.runs_out', { date: formatDate(balance.runs_out_on, locale) }) }}
                            </p>
                            <p class="text-13 text-fg-2">
                                {{
                                    t('bot_brain.costs.left', {
                                        usd: usd(balance.left_usd),
                                        entered: usd(balance.entered_usd),
                                        spent: usd(balance.spent_since),
                                        date: formatDateTime(balance.entered_at, locale),
                                    })
                                }}
                            </p>
                            <p v-if="balance.warn" class="flex items-center gap-1.5 text-13 font-semibold text-danger">
                                <AlertTriangle class="size-4" aria-hidden="true" />{{ t('bot_brain.costs.warn') }}
                            </p>
                        </template>
                        <p v-else class="text-sm text-fg-2">{{ t('bot_brain.costs.not_set') }}</p>
                    </div>
                    <form v-if="canEditBalance" class="space-y-2 border-t border-line px-4 py-3" @submit.prevent="saveBalance()">
                        <p class="text-12 text-fg-3">{{ t('bot_brain.costs.balance_hint') }}</p>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="space-y-1">
                                <span class="text-12 font-semibold text-fg-2">{{ t('bot_brain.costs.balance_input') }}</span>
                                <input
                                    v-model="amount"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputmode="decimal"
                                    dir="ltr"
                                    required
                                    class="h-9 w-full rounded-md border border-line-strong bg-card px-3 text-sm tabular-nums focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                />
                            </label>
                            <label class="space-y-1">
                                <span class="text-12 font-semibold text-fg-2">{{ t('bot_brain.costs.balance_at') }}</span>
                                <input
                                    v-model="readAt"
                                    type="datetime-local"
                                    dir="ltr"
                                    class="h-9 w-full rounded-md border border-line-strong bg-card px-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                />
                            </label>
                        </div>
                        <p v-if="formError" class="text-13 text-danger" role="alert">{{ formError }}</p>
                        <div class="flex gap-2">
                            <Button type="submit" size="sm" :disabled="saving || amount === ''">{{ t('bot_brain.costs.save') }}</Button>
                            <Button v-if="balance.set" type="button" variant="ghost" size="sm" :disabled="saving" @click="saveBalance(true)">{{
                                t('bot_brain.costs.clear')
                            }}</Button>
                        </div>
                    </form>
                    <p v-else class="text-12 border-t border-line px-4 py-3 text-fg-3">{{ t('bot_brain.costs.admin_only') }}</p>
                </section>

                <div class="space-y-4">
                    <Ledger :figures="figures" :label="t('bot_brain.costs.ledger')" />
                    <BarChart :title="t('bot_brain.costs.daily_title')" :items="daily" orientation="vertical" :label-every="5" :format="usd" />
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <section class="rounded-lg border border-line bg-card" aria-labelledby="sonnet-title">
                    <h2 id="sonnet-title" class="border-b border-line px-4 py-3 text-base font-bold">{{ t('bot_brain.costs.sonnet_title') }}</h2>
                    <div class="space-y-2 px-4 py-3 text-sm">
                        <p>{{ t('bot_brain.costs.sonnet_line', { spent: usd(report.sonnet.spent_today), cap: usd(report.sonnet.cap) }) }}</p>
                        <div class="h-2 overflow-hidden rounded-full bg-sunken" role="presentation">
                            <div
                                class="h-full rounded-full"
                                :class="report.sonnet.hit ? 'bg-warn-solid' : 'bg-chart-1'"
                                :style="{
                                    width: `${Math.min(100, report.sonnet.cap > 0 ? (report.sonnet.spent_today / report.sonnet.cap) * 100 : 0)}%`,
                                }"
                            />
                        </div>
                        <StatusChip v-if="report.sonnet.hit" :label="t('bot_brain.costs.sonnet_hit')" tone="warn" />
                    </div>
                </section>

                <section class="rounded-lg border border-line bg-card" aria-labelledby="conv-title">
                    <h2 id="conv-title" class="border-b border-line px-4 py-3 text-base font-bold">{{ t('bot_brain.costs.conversations_title') }}</h2>
                    <dl class="grid grid-cols-2 gap-3 px-4 py-3">
                        <div>
                            <dt class="text-12 text-fg-2">{{ t('bot_brain.costs.per_conversation') }}</dt>
                            <dd class="text-lg font-bold tabular-nums">{{ usd(report.conversations.per_conversation) }}</dd>
                        </div>
                        <div>
                            <dt class="text-12 text-fg-2">{{ t('bot_brain.costs.per_1000') }}</dt>
                            <dd class="text-lg font-bold tabular-nums">{{ usd(report.conversations.per_1000) }}</dd>
                        </div>
                        <p class="text-12 col-span-2 text-fg-3">{{ t('bot_brain.costs.conversations', { n: n(report.conversations.count) }) }}</p>
                    </dl>
                </section>

                <section class="rounded-lg border border-line bg-card" aria-labelledby="cache-title">
                    <h2 id="cache-title" class="border-b border-line px-4 py-3 text-base font-bold">{{ t('bot_brain.costs.cache_title') }}</h2>
                    <div class="space-y-1 px-4 py-3">
                        <p>
                            <span class="text-lg font-bold tabular-nums">{{ pct(report.cache.share) }}</span>
                            <span class="text-13 text-fg-2">{{ t('bot_brain.costs.cache_share') }}</span>
                        </p>
                        <p class="text-12 text-fg-3">{{ t('bot_brain.costs.cache_hint') }}</p>
                    </div>
                </section>
            </div>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <section class="space-y-2" aria-labelledby="models-title">
                    <h2 id="models-title" class="text-base font-bold">{{ t('bot_brain.costs.by_model_title') }}</h2>
                    <DataTable
                        table-id="bot-costs-models"
                        :columns="modelColumns"
                        :rows="modelRows"
                        :empty="t('reports.no_data')"
                        :caption="t('bot_brain.costs.by_model_title')"
                    >
                        <template #cell-model="{ row }"
                            ><bdi dir="ltr">{{ row.model ?? t('bot_brain.costs.no_model') }}</bdi></template
                        >
                        <template #cell-tier="{ row }">{{ tierLabel(row.tier) }}</template>
                        <template #cell-runs="{ row }"
                            ><span class="tabular-nums">{{ n(row.runs) }}</span></template
                        >
                        <template #cell-usd="{ row }"
                            ><span class="tabular-nums">{{ usd(row.usd) }}</span></template
                        >
                    </DataTable>
                </section>

                <section class="rounded-lg border border-line bg-card" aria-labelledby="scenario-title">
                    <h2 id="scenario-title" class="border-b border-line px-4 py-3 text-base font-bold">{{ t('bot_brain.costs.scenarios_title') }}</h2>
                    <dl class="divide-y divide-line text-sm">
                        <div
                            v-for="key in ['legacy', 'hybrid'] as const"
                            :key="key"
                            class="flex items-center justify-between gap-3 px-4 py-3"
                            :data-scenario="key"
                        >
                            <dt>
                                <span class="font-semibold">{{ t(`bot_brain.costs.${key}`) }}</span>
                                <span class="text-12 block text-fg-3">
                                    {{ t('bot_brain.costs.per_turn', { usd: usd(report.scenarios[key].per_turn) }) }} ·
                                    {{ report.scenarios[key].measured ? t('bot_brain.costs.measured') : t('bot_brain.costs.estimated') }}
                                </span>
                            </dt>
                            <dd class="text-lg font-bold tabular-nums">{{ usd(report.scenarios[key].month) }}</dd>
                        </div>
                    </dl>
                    <p class="text-12 border-t border-line px-4 py-2.5 text-fg-3">
                        {{ t('bot_brain.costs.month_turns', { n: n(report.scenarios.month_turns) }) }}
                    </p>
                </section>
            </div>

            <p class="text-12 text-fg-3">{{ t('bot_brain.costs.note') }}</p>
        </div>
    </AppLayout>
</template>
