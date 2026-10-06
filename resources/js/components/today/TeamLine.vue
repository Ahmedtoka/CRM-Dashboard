<script setup lang="ts">
import DataTable from '@/components/crm/DataTable.vue';
import { useI18n } from '@/composables/useI18n';
import { formatCount } from '@/lib/format';
import { formatRatio } from '@/lib/today';
import type { TeamRow, TodayMode } from '@/types/today';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ rows: TeamRow[]; mode: TodayMode; canSeeBoard: boolean }>();
const { t, locale } = useI18n();

const tableRows = computed(() => props.rows.map((r) => ({ id: r.user.id, ...r })));
const columns = computed(() => [
    { key: 'name', label: t('today.team.columns.name'), primary: true },
    ...(props.mode === 'today' ? [{ key: 'state', label: t('today.team.columns.state') }] : []),
    { key: 'windows', label: t('today.team.windows'), align: 'end' as const },
    { key: 'orders', label: t('today.team.orders'), align: 'end' as const },
    { key: 'rating', label: t('today.team.rating'), align: 'end' as const },
]);
const state = (r: TeamRow) => (r.desk_status ? t(`today.team.desk.${r.desk_status}`) : r.online ? t('today.team.online') : t('today.team.offline'));
</script>

<template>
    <section class="min-w-0 rounded-lg bg-card p-4 shadow-card">
        <header class="mb-2 flex items-center gap-2">
            <h2 class="text-sm font-bold text-foreground">{{ t('today.team.title') }}</h2>
            <Link v-if="canSeeBoard" href="/board" class="ms-auto text-sm font-medium text-primary hover:underline">{{ t('today.team.room') }}</Link>
        </header>
        <DataTable :columns="columns" :rows="tableRows" :empty="t('today.team.empty')">
            <template #cell-name="{ row }">
                <Link :href="row.href" class="font-medium text-foreground hover:underline">{{ row.user.name }}</Link>
            </template>
            <template #cell-state="{ row }">
                <span :class="row.online ? 'text-success' : 'text-muted-foreground'">{{ state(row) }}</span>
            </template>
            <template #cell-windows="{ row }">{{ formatCount(row.windows_closed, locale) }}</template>
            <template #cell-orders="{ row }">{{ formatCount(row.orders, locale) }}</template>
            <template #cell-rating="{ row }">
                <span v-if="row.rating.count > 0" :class="row.rating.low > 0 ? 'text-destructive' : ''"
                    >{{ formatRatio(row.rating.avg, locale) }} ({{ formatCount(row.rating.count, locale) }})</span
                >
                <span v-else class="text-muted-foreground">—</span>
            </template>
        </DataTable>
    </section>
</template>
