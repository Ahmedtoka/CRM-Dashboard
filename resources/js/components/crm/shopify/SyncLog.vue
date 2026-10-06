<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useI18n } from '@/composables/useI18n';
import { formatCount, formatDateTime } from '@/lib/format';
import type { ShopifySyncRunRow } from '@/types/admin';
import { AlertTriangle, CheckCircle2, ChevronDown, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';

defineProps<{ runs: ShopifySyncRunRow[] }>();

const { t, locale } = useI18n();

/** Sync-run type/resource codes as words; an unknown code shows as-is. */
function label(group: 'types' | 'resources', value: string): string {
    const key = `settings.shopify.log.${group}.${value}`;
    const text = t(key);
    return text === key ? value : text;
}

const columns = computed<Column[]>(() => [
    { key: 'type', label: t('settings.shopify.log.type'), primary: true },
    { key: 'resource', label: t('settings.shopify.log.resource') },
    { key: 'status', label: t('settings.shopify.log.status') },
    { key: 'processed', label: t('settings.shopify.log.processed'), numeric: true },
    { key: 'created', label: t('settings.shopify.log.created'), numeric: true },
    { key: 'updated', label: t('settings.shopify.log.updated'), numeric: true },
    { key: 'skipped_stale', label: t('settings.shopify.log.skipped'), numeric: true },
    { key: 'failed', label: t('settings.shopify.log.failed'), numeric: true },
    { key: 'finished_at', label: t('settings.shopify.log.finished_at') },
    { key: 'errors', label: t('settings.shopify.log.errors'), align: 'end' },
]);

// Text stays `text-foreground` for contrast; the icon (when present) carries the status color.
const statusTone: Record<string, string> = { running: 'text-primary' };
const statusIcon: Record<string, typeof CheckCircle2> = { completed: CheckCircle2, ok: CheckCircle2, failed: XCircle, partial: AlertTriangle };
const statusIconTone: Record<string, string> = { completed: 'text-success', ok: 'text-success', failed: 'text-destructive', partial: 'text-warning' };
</script>

<template>
    <section class="grid gap-2">
        <h2 class="text-sm font-semibold">{{ t('settings.shopify.log.title') }}</h2>

        <DataTable table-id="shopify-sync-log" :columns="columns" :rows="runs" mobile="scroll" :empty="t('settings.shopify.log.empty')" :caption="t('settings.shopify.log.title')">
            <template #cell-type="{ row }">{{ label('types', row.type) }}</template>
            <template #cell-resource="{ row }">{{ label('resources', row.resource) }}</template>
            <template #cell-status="{ row }">
                <span class="inline-flex items-center gap-1 font-medium" :class="statusTone[row.status] ?? 'text-foreground'">
                    <component :is="statusIcon[row.status]" v-if="statusIcon[row.status]" class="size-3.5" :class="statusIconTone[row.status]" aria-hidden="true" />
                    {{ t(`settings.shopify.log.run_status.${row.status}`, {}) || row.status }}
                </span>
            </template>
            <template #cell-failed="{ row }">
                <span :class="row.failed > 0 ? 'rounded bg-destructive/10 px-1 font-semibold text-foreground' : ''">{{ formatCount(row.failed, locale) }}</span>
            </template>
            <template #cell-finished_at="{ row }">
                <span class="whitespace-nowrap tabular-nums text-muted-foreground">{{ formatDateTime(row.finished_at, locale) || '—' }}</span>
            </template>
            <template #cell-errors="{ row }">
                <Popover v-if="row.errors.length">
                    <PopoverTrigger
                        class="inline-flex items-center gap-1 rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                        :aria-label="t('settings.shopify.log.errors')"
                    >
                        {{ formatCount(row.errors.length, locale) }}
                        <ChevronDown class="size-3.5" aria-hidden="true" />
                    </PopoverTrigger>
                    <PopoverContent class="w-96 max-w-[calc(100vw-2rem)] p-3" :collision-padding="16">
                        <ul class="grid gap-1 text-xs">
                            <li v-for="(error, i) in row.errors" :key="i" class="flex flex-wrap items-start gap-2">
                                <code class="text-2xs text-muted-foreground" dir="ltr">{{ error.ref }}</code>
                                <span class="flex items-start gap-1 break-words text-foreground" dir="ltr">
                                    <XCircle class="mt-0.5 size-3 shrink-0 text-destructive" aria-hidden="true" />{{ error.message }}
                                </span>
                            </li>
                        </ul>
                    </PopoverContent>
                </Popover>
                <span v-else class="text-muted-foreground">—</span>
            </template>
        </DataTable>
    </section>
</template>
