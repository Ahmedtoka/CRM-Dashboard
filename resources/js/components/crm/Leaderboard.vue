<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import { useI18n } from '@/composables/useI18n';
import { formatAvgSeconds, formatCount, formatMinutes, formatMoney } from '@/lib/format';
import type { LeaderboardRow } from '@/types/admin';
import { computed } from 'vue';

const props = defineProps<{ rows: LeaderboardRow[]; onlineUserIds: number[] }>();
const emit = defineEmits<{ open: [userId: number] }>();

const { t, locale } = useI18n();

const tableRows = computed(() => props.rows.map((row) => ({ ...row, id: row.user.id })));
const rankOf = (userId: number) => props.rows.findIndex((row) => row.user.id === userId) + 1;

const columns = computed<Column[]>(() => [
    { key: 'moderator', label: t('reports.lb.moderator'), primary: true },
    { key: 'messages_sent', label: t('reports.lb.messages'), numeric: true },
    { key: 'conversations_handled', label: t('reports.lb.conversations'), numeric: true },
    { key: 'first_responses', label: t('reports.lb.first'), numeric: true },
    { key: 'continued', label: t('reports.lb.continued'), numeric: true },
    { key: 'follow_ups', label: t('reports.lb.follow_ups'), numeric: true },
    { key: 'avg_first_response_sec', label: t('reports.lb.avg_first_response'), numeric: true },
    { key: 'orders_count', label: t('reports.lb.orders'), numeric: true },
    { key: 'orders_total', label: t('reports.lb.revenue'), numeric: true },
    { key: 'online_minutes', label: t('reports.lb.online'), numeric: true },
]);

const COUNT_KEYS = ['messages_sent', 'conversations_handled', 'first_responses', 'continued', 'follow_ups', 'orders_count'] as const;
</script>

<template>
    <DataTable
        table-id="team-leaderboard"
        :columns="columns"
        :rows="tableRows"
        clickable
        :caption="t('reports.leaderboard')"
        @row-click="emit('open', $event.user.id)"
    >
        <template #cell-moderator="{ row }">
            <span class="inline-flex items-center gap-2 whitespace-nowrap font-medium">
                <span
                    class="flex size-5 shrink-0 items-center justify-center rounded-full bg-sunken text-[11px] font-semibold tabular-nums text-fg-2"
                    >{{ formatCount(rankOf(row.user.id), locale) }}</span
                >
                <span class="size-2.5 shrink-0 rounded-full" :style="{ backgroundColor: row.user.color ?? 'hsl(var(--fg-3))' }" aria-hidden="true" />
                <span class="text-brand-ink">{{ row.user.name }}</span>
                <span v-if="onlineUserIds.includes(row.user.id)" class="size-2 rounded-full bg-ok" :title="t('reports.online_now')">
                    <span class="sr-only">{{ t('reports.online_now') }}</span>
                </span>
            </span>
        </template>
        <template v-for="key in COUNT_KEYS" :key="key" #[`cell-${key}`]="{ value }">
            <span class="tabular-nums">{{ formatCount(value as number, locale) }}</span>
        </template>
        <template #cell-avg_first_response_sec="{ value }">
            <span class="tabular-nums">{{ formatAvgSeconds(value as number, locale) }}</span>
        </template>
        <template #cell-orders_total="{ value }">
            <span class="whitespace-nowrap tabular-nums">{{ formatMoney(value as number, locale) }}</span>
        </template>
        <template #cell-online_minutes="{ value }">
            <span class="tabular-nums">{{ formatMinutes(value as number, locale) }}</span>
        </template>
    </DataTable>
</template>
