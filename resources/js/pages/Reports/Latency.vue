<script setup lang="ts">
import EmptyState from '@/components/crm/EmptyState.vue';
import FilterBar from '@/components/crm/FilterBar.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { formatNumber } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { DISPLAY_TIMEZONE, formatDateTime } from '@/lib/format';
import type { LatencyKind, LatencyKindStats, LatencyWindow } from '@/types/admin';
import { Head, router } from '@inertiajs/vue3';
import { Timer } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    window: LatencyWindow;
    range: { from: string; to: string };
    targets: Record<LatencyKind, number>;
    kinds: Record<LatencyKind, LatencyKindStats>;
}>();

const { t, locale } = useI18n();

const WINDOWS: LatencyWindow[] = ['15m', '1h', '24h', 'custom'];
const KINDS: LatencyKind[] = ['inbound', 'outbound', 'list'];

/** Cairo wall-clock "YYYY-MM-DDTHH:mm" for a UTC ISO instant, to seed the custom inputs. */
function toCairoLocalInput(iso: string): string {
    const d = new Date(iso);
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: DISPLAY_TIMEZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(d);
    const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '00';

    return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}

const showCustom = ref(props.window === 'custom');
const customFrom = ref(toCairoLocalInput(props.range.from));
const customTo = ref(toCairoLocalInput(props.range.to));

const customInvalid = computed(() => !customFrom.value || !customTo.value || customTo.value < customFrom.value);

function choose(w: LatencyWindow): void {
    if (w === 'custom') showCustom.value = !showCustom.value;
}

// The fixed windows are presets (links, so a reload or a shared address keeps them); «مخصص» opens the inputs.
const presets = computed(() =>
    WINDOWS.filter((w) => w !== 'custom').map((w) => ({
        key: w,
        label: t(`reports.latency.window_${w}`),
        href: `/reports/latency?window=${w}`,
        active: props.window === w,
    })),
);
const summary = computed(() =>
    t('range.summary', { from: formatDateTime(props.range.from, locale.value), to: formatDateTime(props.range.to, locale.value) }),
);

function applyCustom(): void {
    if (customInvalid.value) return;

    router.get(
        '/reports/latency',
        { window: 'custom', from: customFrom.value, to: customTo.value },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function n(v: number): string {
    return formatNumber(locale.value, v);
}

const cards = computed(() =>
    KINDS.map((kind) => ({
        kind,
        label: t(`reports.latency.kind_${kind}`),
        stats: props.kinds[kind],
    })),
);

const breadcrumbs = computed(() => [{ title: t('reports.latency.title'), href: '/reports/latency' }]);
</script>

<template>
    <Head :title="t('reports.latency.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('reports.latency.title')" :description="t('reports.latency.hint')" />

            <FilterBar :chips="[]" :presets="presets" :summary="summary">
                <template #inline>
                    <button
                        type="button"
                        :aria-pressed="window === 'custom' || showCustom"
                        :aria-expanded="showCustom"
                        class="h-10 rounded-md border px-3 text-13 font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :class="
                            window === 'custom' || showCustom
                                ? 'border-brand bg-brand text-brand-on'
                                : 'border-line-strong bg-card text-fg-2 hover:text-foreground'
                        "
                        @click="choose('custom')"
                    >
                        {{ t('reports.latency.window_custom') }}
                    </button>
                    <form v-if="showCustom" class="flex flex-wrap items-center gap-1.5" @submit.prevent="applyCustom">
                        <label class="sr-only" for="latency-from">{{ t('reports.latency.from') }}</label>
                        <input
                            id="latency-from"
                            v-model="customFrom"
                            type="datetime-local"
                            dir="ltr"
                            class="h-10 rounded-md border border-line-strong bg-card px-2 text-13 tabular-nums"
                            :max="customTo"
                        />
                        <span class="text-13 text-fg-2" aria-hidden="true">{{ t('reports.latency.to') }}</span>
                        <label class="sr-only" for="latency-to">{{ t('reports.latency.to') }}</label>
                        <input
                            id="latency-to"
                            v-model="customTo"
                            type="datetime-local"
                            dir="ltr"
                            class="h-10 rounded-md border border-line-strong bg-card px-2 text-13 tabular-nums"
                            :min="customFrom"
                        />
                        <button
                            type="submit"
                            class="h-10 rounded-md bg-brand px-4 text-13 font-semibold text-brand-on hover:bg-brand-hover disabled:opacity-50"
                            :disabled="customInvalid"
                        >
                            {{ t('reports.latency.apply') }}
                        </button>
                    </form>
                </template>
            </FilterBar>

            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3 [&>*]:min-w-0">
                <section v-for="card in cards" :key="card.kind" class="space-y-3 rounded-lg border border-line bg-card p-4">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="text-base font-bold">{{ card.label }}</h2>
                        <StatusChip v-if="card.stats.pass === null" :label="t('reports.latency.no_data')" tone="neutral" />
                        <StatusChip
                            v-else
                            :label="card.stats.pass ? t('reports.latency.pass') : t('reports.latency.fail')"
                            :tone="card.stats.pass ? 'ok' : 'danger'"
                        />
                    </div>

                    <EmptyState v-if="card.stats.count === 0" :icon="Timer" :title="t('reports.latency.no_data')" class="p-4" />
                    <dl v-else class="grid grid-cols-2 gap-x-3 gap-y-2.5 text-13">
                        <div>
                            <dt class="text-fg-2">{{ t('reports.latency.count') }}</dt>
                            <dd class="font-medium tabular-nums">{{ n(card.stats.count) }}</dd>
                        </div>
                        <div>
                            <dt class="text-fg-2">{{ t('reports.latency.target') }}</dt>
                            <dd class="font-medium tabular-nums">{{ n(card.stats.target) }} {{ t('reports.latency.unit_ms') }}</dd>
                        </div>
                        <div>
                            <dt class="text-fg-2">{{ t('reports.latency.p50') }}</dt>
                            <dd class="font-medium tabular-nums">{{ n(card.stats.p50) }} {{ t('reports.latency.unit_ms') }}</dd>
                        </div>
                        <div>
                            <dt class="text-fg-2">{{ t('reports.latency.p95') }}</dt>
                            <dd class="font-semibold tabular-nums" :class="{ 'text-danger': card.stats.pass === false }">
                                {{ n(card.stats.p95) }} {{ t('reports.latency.unit_ms') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-fg-2">{{ t('reports.latency.p99') }}</dt>
                            <dd class="font-medium tabular-nums">{{ n(card.stats.p99) }} {{ t('reports.latency.unit_ms') }}</dd>
                        </div>
                        <div>
                            <dt class="text-fg-2">{{ t('reports.latency.max') }}</dt>
                            <dd class="font-medium tabular-nums">{{ n(card.stats.max) }} {{ t('reports.latency.unit_ms') }}</dd>
                        </div>
                    </dl>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
