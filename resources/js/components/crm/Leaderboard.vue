<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import { useI18n } from '@/composables/useI18n';
import { formatCount, formatMinutes, formatMoney, formatSeconds } from '@/lib/format';
import type { LeaderboardRow } from '@/types/admin';
import { computed } from 'vue';

const props = defineProps<{ rows: LeaderboardRow[]; onlineUserIds: number[] }>();
const emit = defineEmits<{ open: [userId: number] }>();

const { t, locale } = useI18n();

const tableRows = computed(() => props.rows.map((row) => ({ ...row, id: row.user.id })));
const rankOf = (userId: number) => props.rows.findIndex((row) => row.user.id === userId) + 1;

const columns = computed<Column[]>(() => [
    { key: 'moderator', label: t('reports.lb.moderator') },
    { key: 'messages_sent', label: t('reports.lb.messages'), align: 'end' },
    { key: 'conversations_handled', label: t('reports.lb.conversations'), align: 'end' },
    { key: 'first_responses', label: t('reports.lb.first'), align: 'end' },
    { key: 'continued', label: t('reports.lb.continued'), align: 'end' },
    { key: 'follow_ups', label: t('reports.lb.follow_ups'), align: 'end' },
    { key: 'avg_first_response_sec', label: t('reports.lb.avg_first_response'), align: 'end' },
    { key: 'orders_count', label: t('reports.lb.orders'), align: 'end' },
    { key: 'orders_total', label: t('reports.lb.revenue'), align: 'end' },
    { key: 'online_minutes', label: t('reports.lb.online'), align: 'end' },
]);

const COUNT_KEYS = ['messages_sent', 'conversations_handled', 'first_responses', 'continued', 'follow_ups', 'orders_count'] as const;
</script>

<template>
    <DataTable :columns="columns" :rows="tableRows" clickable :caption="t('reports.leaderboard')" @row-click="emit('open', $event.user.id)">
        <template #cell-moderator="{ row }">
            <span class="inline-flex items-center gap-2 whitespace-nowrap font-medium">
                <span class="flex size-5 shrink-0 items-center justify-center rounded-full bg-elevated text-2xs font-semibold text-muted-foreground">{{ rankOf(row.user.id) }}</span>
                <span class="size-2.5 rounded-full" :style="{ backgroundColor: row.user.color ?? '#94a3b8' }" aria-hidden="true" />
                {{ row.user.name }}
                <span v-if="onlineUserIds.includes(row.user.id)" class="size-1.5 rounded-full bg-success" :title="t('reports.online_now')">
                    <span class="sr-only">{{ t('reports.online_now') }}</span>
                </span>
            </span>
        </template>
        <template v-for="key in COUNT_KEYS" :key="key" #[`cell-${key}`]="{ value }">
            <span class="tabular-nums">{{ formatCount(value as number, locale) }}</span>
        </template>
        <template #cell-avg_first_response_sec="{ value }">
            <span class="tabular-nums">{{ formatSeconds(value as number, locale) }}</span>
        </template>
        <template #cell-orders_total="{ value }">
            <span class="whitespace-nowrap tabular-nums">{{ formatMoney(value as number, locale) }}</span>
        </template>
        <template #cell-online_minutes="{ value }">
            <span class="tabular-nums">{{ formatMinutes(value as number, locale) }}</span>
        </template>
    </DataTable>
</template>
