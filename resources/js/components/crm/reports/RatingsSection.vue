<script setup lang="ts">
import DataTable from '@/components/crm/DataTable.vue';
import { useI18n } from '@/composables/useI18n';
import { formatCount, formatDate, formatDateTime } from '@/lib/format';
import { formatRatio } from '@/lib/today';
import type { TeamRatings } from '@/types/today';
import { Link, router } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';

const props = defineProps<{ ratings: TeamRatings; range: { from: string; to: string } }>();
const { t, locale } = useI18n();

const rows = computed(() => props.ratings.by_agent_day.map((r) => ({ id: `${r.user.id}-${r.date}`, ...r })));
const columns = computed(() => [
    { key: 'date', label: t('reports.ratings.columns.date'), primary: true },
    { key: 'agent', label: t('reports.ratings.columns.agent') },
    { key: 'count', label: t('reports.ratings.columns.count'), align: 'end' as const },
    { key: 'avg', label: t('reports.ratings.columns.avg'), align: 'end' as const },
    { key: 'low', label: t('reports.ratings.columns.low'), align: 'end' as const },
]);

function setStars(stars: 'low' | 'all'): void {
    router.get('/reports/team', { ...props.range, stars }, { preserveScroll: true, preserveState: true, only: ['ratings'] });
}

// The «النهارده» link opens this section: /reports/team?…&stars=low#ratings.
onMounted(() => {
    if (window.location.hash === '#ratings' || props.ratings.stars === 'low') document.getElementById('ratings')?.scrollIntoView({ block: 'start' });
});
</script>

<template>
    <section id="ratings" class="space-y-3 rounded-lg bg-card p-4 shadow-card">
        <header class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <h2 class="text-base font-bold text-foreground">{{ t('reports.ratings.title') }}</h2>
            <p v-if="ratings.summary.count > 0" class="text-sm tabular-nums text-muted-foreground">
                {{ t('reports.ratings.summary', { avg: formatRatio(ratings.summary.avg, locale), n: formatCount(ratings.summary.count, locale) }) }}
                <span v-if="ratings.summary.low > 0" class="ms-2 font-semibold text-destructive">{{ t('reports.ratings.low', { n: formatCount(ratings.summary.low, locale) }) }}</span>
            </p>
        </header>

        <p v-if="ratings.summary.count === 0" class="text-sm text-muted-foreground">{{ t('reports.ratings.empty') }}</p>

        <template v-else>
            <h3 class="text-xs font-semibold text-muted-foreground">{{ t('reports.ratings.by_agent_day') }}</h3>
            <DataTable :columns="columns" :rows="rows">
                <template #cell-date="{ row }">{{ formatDate(`${row.date}T12:00:00Z`, locale) }}</template>
                <template #cell-agent="{ row }">{{ row.user.name }}</template>
                <template #cell-avg="{ row }">{{ formatRatio(row.avg, locale) }}</template>
                <template #cell-low="{ row }">
                    <span :class="row.low > 0 ? 'font-semibold text-destructive' : ''">{{ formatCount(row.low, locale) }}</span>
                </template>
            </DataTable>

            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-xs font-semibold text-muted-foreground">{{ t('reports.ratings.list') }}</h3>
                <div class="ms-auto inline-flex rounded-md border border-border p-0.5 text-xs" role="group">
                    <button
                        v-for="s in ['all', 'low'] as const"
                        :key="s"
                        type="button"
                        class="rounded px-2 py-1"
                        :class="ratings.stars === s ? 'bg-primary text-primary-foreground' : 'text-muted-foreground'"
                        :aria-pressed="ratings.stars === s"
                        @click="setStars(s)"
                    >
                        {{ s === 'low' ? t('reports.ratings.low_only') : t('reports.ratings.all') }}
                    </button>
                </div>
            </div>
            <ul class="divide-y divide-border text-sm">
                <li v-for="r in ratings.list" :key="r.entry_id" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                    <span class="w-10 font-bold tabular-nums" :class="r.stars <= 2 ? 'text-destructive' : 'text-foreground'">{{ formatCount(r.stars, locale) }}/{{ formatCount(5, locale) }}</span>
                    <span class="min-w-0 flex-1 truncate">{{ r.customer ?? '—' }} · {{ r.user?.name ?? '—' }}</span>
                    <span class="text-xs tabular-nums text-muted-foreground">{{ formatDateTime(r.reviewed_at, locale) }}</span>
                    <Link v-if="r.conversation_id" :href="`/inbox?c=${r.conversation_id}`" class="text-xs font-medium text-primary hover:underline">{{
                        t('reports.ratings.open')
                    }}</Link>
                </li>
            </ul>
        </template>
    </section>
</template>
