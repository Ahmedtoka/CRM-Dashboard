<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatCount, formatDateTime } from '@/lib/format';
import type { ShopifySyncRunRow } from '@/types/admin';
import { AlertTriangle, CheckCircle2, ChevronDown, ChevronUp, XCircle } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{ runs: ShopifySyncRunRow[] }>();

const { t, locale } = useI18n();

const expanded = ref<Set<number>>(new Set());

/** Sync-run type/resource codes as words; an unknown code shows as-is. */
function label(group: 'types' | 'resources', value: string): string {
    const key = `settings.shopify.log.${group}.${value}`;
    const text = t(key);
    return text === key ? value : text;
}

function toggle(id: number): void {
    const next = new Set(expanded.value);
    next.has(id) ? next.delete(id) : next.add(id);
    expanded.value = next;
}

// Text stays `text-foreground` for contrast; the icon (when present) carries the status color.
const statusTone: Record<string, string> = { running: 'text-primary' };
const statusIcon: Record<string, typeof CheckCircle2> = { completed: CheckCircle2, ok: CheckCircle2, failed: XCircle, partial: AlertTriangle };
const statusIconTone: Record<string, string> = { completed: 'text-success', ok: 'text-success', failed: 'text-destructive', partial: 'text-warning' };
</script>

<template>
    <section class="grid gap-2">
        <h2 class="text-sm font-semibold">{{ t('settings.shopify.log.title') }}</h2>

        <div class="scrollbar-thin overflow-x-auto rounded-lg bg-card shadow-card">
            <table class="w-full text-xs">
                <thead class="border-b border-border bg-muted/50 text-2xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 text-start font-medium">{{ t('settings.shopify.log.type') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ t('settings.shopify.log.resource') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ t('settings.shopify.log.status') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ t('settings.shopify.log.processed') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ t('settings.shopify.log.created') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ t('settings.shopify.log.updated') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ t('settings.shopify.log.skipped') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ t('settings.shopify.log.failed') }}</th>
                        <th class="px-3 py-2 text-start font-medium">{{ t('settings.shopify.log.finished_at') }}</th>
                        <th class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!runs.length">
                        <td colspan="10" class="px-3 py-8 text-center text-muted-foreground">{{ t('settings.shopify.log.empty') }}</td>
                    </tr>
                    <template v-for="run in runs" :key="run.id">
                        <tr class="border-b border-border last:border-0">
                            <td class="px-3 py-2">{{ label('types', run.type) }}</td>
                            <td class="px-3 py-2">{{ label('resources', run.resource) }}</td>
                            <td class="px-3 py-2 font-medium" :class="statusTone[run.status] ?? 'text-foreground'">
                                <span class="inline-flex items-center gap-1">
                                    <component :is="statusIcon[run.status]" v-if="statusIcon[run.status]" class="size-3.5" :class="statusIconTone[run.status]" aria-hidden="true" />
                                    {{ t(`settings.shopify.log.run_status.${run.status}`, {}) || run.status }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ formatCount(run.processed, locale) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ formatCount(run.created, locale) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ formatCount(run.updated, locale) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ formatCount(run.skipped_stale, locale) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums" :class="run.failed > 0 ? 'rounded bg-destructive/10 font-semibold text-foreground' : ''">{{ formatCount(run.failed, locale) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 tabular-nums text-muted-foreground">{{ formatDateTime(run.finished_at, locale) || '—' }}</td>
                            <td class="px-3 py-2 text-end">
                                <button
                                    v-if="run.errors.length"
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                    :aria-label="t('settings.shopify.log.errors')"
                                    @click="toggle(run.id)"
                                >
                                    {{ formatCount(run.errors.length, locale) }}
                                    <ChevronUp v-if="expanded.has(run.id)" class="size-3.5" aria-hidden="true" />
                                    <ChevronDown v-else class="size-3.5" aria-hidden="true" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="expanded.has(run.id)" class="border-b border-border bg-muted/30 last:border-0">
                            <td colspan="10" class="px-3 py-2">
                                <ul class="grid gap-1">
                                    <li v-for="(error, i) in run.errors" :key="i" class="flex flex-wrap items-start gap-2">
                                        <code class="text-2xs text-muted-foreground" dir="ltr">{{ error.ref }}</code>
                                        <span class="flex items-start gap-1 break-words text-foreground" dir="ltr">
                                            <XCircle class="mt-0.5 size-3 shrink-0 text-destructive" aria-hidden="true" />{{ error.message }}
                                        </span>
                                    </li>
                                </ul>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </section>
</template>
