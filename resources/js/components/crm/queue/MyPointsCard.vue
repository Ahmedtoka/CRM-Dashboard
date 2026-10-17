<script setup lang="ts">
import PointsLedger from '@/components/crm/queue/PointsLedger.vue';
import { useI18n } from '@/composables/useI18n';
import { formatCount } from '@/lib/format';
import type { PointsKind, PointsLine, PointsSummary } from '@/types/queue';
import { computed } from 'vue';

/** «نقطي» on /reports/me (queue Part 2): her own points of the range, per kind, and every line with its reason. */
const props = defineProps<{ points: PointsSummary & { ledger: PointsLine[] } }>();
const { t, locale } = useI18n();

const kinds = computed(() =>
    (Object.keys(props.points.by_kind) as PointsKind[])
        .filter((k) => props.points.by_kind[k] !== 0)
        .map((k) => ({ key: k, value: props.points.by_kind[k] })),
);

function signed(n: number): string {
    return `${n > 0 ? '+' : ''}${formatCount(n, locale.value)}`;
}
</script>

<template>
    <section class="space-y-3 rounded-lg bg-card p-4 shadow-card" :aria-label="t('queue_points.mine')">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-sm font-semibold text-foreground">{{ t('queue_points.mine') }}</h2>
            <span class="text-2xl font-bold tabular-nums text-foreground">{{ formatCount(points.total, locale) }}</span>
        </div>
        <p class="text-xs text-muted-foreground">{{ t('queue_points.mine_hint') }}</p>
        <ul v-if="kinds.length" class="flex flex-wrap gap-1 text-xs">
            <li v-for="k in kinds" :key="k.key" class="rounded-full bg-muted px-2 py-0.5 tabular-nums">
                {{ t(`queue_points.kinds.${k.key}`) }} {{ signed(k.value) }}
            </li>
        </ul>
        <PointsLedger :lines="points.ledger" compact show-day />
    </section>
</template>
