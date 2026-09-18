<script setup lang="ts">
import PageHeader from '@/components/crm/PageHeader.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { formatNumber } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { DISPLAY_TIMEZONE } from '@/lib/format';
import type { LatencyKind, LatencyKindStats, LatencyWindow } from '@/types/admin';
import { Head, router } from '@inertiajs/vue3';
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
    if (w === 'custom') {
        showCustom.value = true;
        return;
    }

    showCustom.value = false;
    router.get('/reports/latency', { window: w }, { preserveScroll: true, preserveState: true, replace: true });
}

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

            <div class="flex flex-wrap items-center gap-1.5 rounded-lg bg-card p-3 shadow-card" role="group" :aria-label="t('reports.latency.window_label')">
                <button
                    v-for="w in WINDOWS"
                    :key="w"
                    type="button"
                    :aria-pressed="window === w"
                    class="h-9 rounded-md border px-2.5 text-xs transition-colors"
                    :class="window === w ? 'border-primary bg-primary text-primary-foreground' : 'bg-background text-muted-foreground hover:text-foreground'"
                    @click="choose(w)"
                >
                    {{ t(`reports.latency.window_${w}`) }}
                </button>

                <form v-if="showCustom" class="flex flex-wrap items-center gap-1.5" @submit.prevent="applyCustom">
                    <label class="sr-only" for="latency-from">{{ t('reports.latency.from') }}</label>
                    <input
                        id="latency-from"
                        v-model="customFrom"
                        type="datetime-local"
                        dir="ltr"
                        class="h-9 rounded-md border border-input bg-background px-2 text-xs"
                        :max="customTo"
                    />
                    <span class="text-xs text-muted-foreground" aria-hidden="true">{{ t('reports.latency.to') }}</span>
                    <label class="sr-only" for="latency-to">{{ t('reports.latency.to') }}</label>
                    <input
                        id="latency-to"
                        v-model="customTo"
                        type="datetime-local"
                        dir="ltr"
                        class="h-9 rounded-md border border-input bg-background px-2 text-xs"
                        :min="customFrom"
                    />
                    <button
                        type="submit"
                        class="h-9 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground disabled:opacity-50"
                        :disabled="customInvalid"
                    >
                        {{ t('reports.latency.apply') }}
                    </button>
                </form>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <section v-for="card in cards" :key="card.kind" class="space-y-2 rounded-lg bg-card p-3 shadow-card">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="text-xs font-medium">{{ card.label }}</h2>
                        <StatusChip v-if="card.stats.pass === null" :label="t('reports.latency.no_data')" tone="neutral" />
                        <StatusChip
                            v-else
                            :label="card.stats.pass ? t('reports.latency.pass') : t('reports.latency.fail')"
                            :tone="card.stats.pass ? 'positive' : 'negative'"
                        />
                    </div>

                    <dl class="grid grid-cols-2 gap-x-2 gap-y-1.5 text-xs">
                        <div>
                            <dt class="text-muted-foreground">{{ t('reports.latency.count') }}</dt>
                            <dd class="tabular-nums font-medium">{{ n(card.stats.count) }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">{{ t('reports.latency.target') }}</dt>
                            <dd class="tabular-nums font-medium">{{ n(card.stats.target) }} {{ t('reports.latency.unit_ms') }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">{{ t('reports.latency.p50') }}</dt>
                            <dd class="tabular-nums font-medium">{{ n(card.stats.p50) }} {{ t('reports.latency.unit_ms') }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">{{ t('reports.latency.p95') }}</dt>
                            <dd class="tabular-nums font-semibold" :class="{ 'text-destructive': card.stats.pass === false }">
                                {{ n(card.stats.p95) }} {{ t('reports.latency.unit_ms') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">{{ t('reports.latency.p99') }}</dt>
                            <dd class="tabular-nums font-medium">{{ n(card.stats.p99) }} {{ t('reports.latency.unit_ms') }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">{{ t('reports.latency.max') }}</dt>
                            <dd class="tabular-nums font-medium">{{ n(card.stats.max) }} {{ t('reports.latency.unit_ms') }}</dd>
                        </div>
                    </dl>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
