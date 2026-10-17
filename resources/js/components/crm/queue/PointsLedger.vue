<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatClock, formatCount, formatDay } from '@/lib/format';
import type { PointsLine } from '@/types/queue';
import { computed, ref } from 'vue';

/**
 * A moderator's points ledger (queue Part 2): one row per line with its reason, newest first.
 * Taken-back lines are struck through; a reversal shows as its own negative row. `compact` shows
 * the first five with «كل السطور».
 */
const props = defineProps<{ lines: PointsLine[]; compact?: boolean; showDay?: boolean }>();
const { t, locale } = useI18n();

const all = ref(false);
const shown = computed(() => (props.compact && !all.value ? props.lines.slice(0, 5) : props.lines));

function signed(n: number): string {
    return `${n > 0 ? '+' : ''}${formatCount(n, locale.value)}`;
}
</script>

<template>
    <div>
        <p v-if="lines.length === 0" class="text-xs text-muted-foreground">{{ t('queue_points.empty') }}</p>
        <ul v-else class="divide-y divide-border rounded-md border border-border text-xs">
            <li v-for="line in shown" :key="line.id" class="flex items-center gap-2 px-2 py-1.5">
                <span
                    class="w-10 shrink-0 text-end font-bold tabular-nums"
                    :class="[
                        line.points > 0 ? 'text-success' : line.points < 0 ? 'text-destructive' : 'text-muted-foreground',
                        line.reversed ? 'line-through opacity-60' : '',
                    ]"
                    >{{ signed(line.points) }}</span
                >
                <span class="min-w-0 flex-1 truncate text-foreground" :class="line.reversed ? 'line-through opacity-60' : ''" :title="line.reason">{{
                    line.reason
                }}</span>
                <span class="shrink-0 tabular-nums text-muted-foreground">{{
                    showDay ? `${formatDay(line.at, locale)} ${formatClock(line.at, locale)}` : formatClock(line.at, locale)
                }}</span>
            </li>
        </ul>
        <button
            v-if="compact && lines.length > 5"
            type="button"
            class="mt-1 text-xs font-semibold text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            @click="all = !all"
        >
            {{ all ? t('queue_points.fewer') : t('queue_points.all', { n: formatCount(lines.length, locale) }) }}
        </button>
    </div>
</template>
