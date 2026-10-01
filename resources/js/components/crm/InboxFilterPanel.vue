<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatCount } from '@/lib/format';
import type { InboxCounts, InboxFilters, InboxModerator, InboxQueueFilter, InboxQuickFilter, PlatformOption, Tag } from '@/types/crm';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        filters: InboxFilters;
        counts: InboxCounts | null;
        queueEnabled?: boolean;
        moderators: InboxModerator[];
        tags: Tag[];
        platforms: PlatformOption[];
        flagOptions: InboxQuickFilter[];
    }>(),
    { queueEnabled: false },
);
const emit = defineEmits<{ update: [patch: Partial<InboxFilters>] }>();
const { t, locale } = useI18n();

const QUEUE_STATES: InboxQueueFilter[] = ['waiting', 'window', 'overdue', 'returning'];

const countLabel = (n: number | undefined) => {
    if (n === undefined || !props.counts) return '';
    return n > props.counts.capped_at ? `${formatCount(props.counts.capped_at, locale.value)}+` : formatCount(n, locale.value);
};

const flagSet = computed(() => new Set(props.filters.flags));

function toggleFlag(flag: InboxQuickFilter): void {
    const next = flagSet.value.has(flag) ? props.filters.flags.filter((f) => f !== flag) : [...props.filters.flags, flag];
    emit('update', { flags: next });
}

const valueOf = (event: Event) => (event.target as HTMLSelectElement).value || null;
const selectClass =
    'h-9 w-full rounded-md border border-input bg-background px-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
const legendClass = 'mb-1.5 text-xs font-semibold text-foreground';
</script>

<template>
    <div class="max-h-[min(70vh,32rem)] space-y-4 overflow-y-auto pe-1">
        <fieldset v-if="queueEnabled">
            <legend :class="legendClass">{{ t('inbox.filters_panel.queue') }}</legend>
            <div class="grid grid-cols-2 gap-1.5">
                <label
                    v-for="q in [null, ...QUEUE_STATES]"
                    :key="q ?? 'all'"
                    class="flex h-8 cursor-pointer items-center gap-2 rounded-md border px-2 text-xs has-[:checked]:border-primary has-[:checked]:bg-surface-accent has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                >
                    <input
                        type="radio"
                        name="inbox-queue"
                        class="sr-only"
                        :checked="filters.queue === q"
                        @change="emit('update', { queue: q })"
                    />
                    <span class="min-w-0 flex-1 truncate">{{ q ? t(`inbox.queue_state.${q}`) : t('inbox.tabs.all') }}</span>
                    <span v-if="q" class="shrink-0 tabular-nums text-muted-foreground">{{ countLabel(counts?.queue?.[q]) }}</span>
                </label>
            </div>
        </fieldset>

        <label class="block">
            <span :class="legendClass" class="block">{{ t('inbox.filters_panel.moderator') }}</span>
            <select :value="filters.assignee ?? ''" :class="selectClass" @change="emit('update', { assignee: valueOf($event) })">
                <option value="">{{ t('inbox.tabs.all') }}</option>
                <option value="me">{{ t('inbox.assignee.me') }}</option>
                <option value="none">{{ t('inbox.assignee.none') }}</option>
                <option v-for="m in moderators" :key="m.id" :value="String(m.id)">{{ m.name }}</option>
            </select>
        </label>

        <div class="grid grid-cols-2 gap-2">
            <label class="block min-w-0">
                <span :class="legendClass" class="block">{{ t('inbox.filters_panel.platform') }}</span>
                <select
                    :value="filters.platform ?? ''"
                    :class="selectClass"
                    @change="emit('update', { platform: valueOf($event) as InboxFilters['platform'] })"
                >
                    <option value="">{{ t('inbox.platform_all') }}</option>
                    <option v-for="p in platforms" :key="p.value" :value="p.value">{{ p.label }}</option>
                </select>
            </label>
            <label class="block min-w-0">
                <span :class="legendClass" class="block">{{ t('inbox.filters_panel.tag') }}</span>
                <select :value="filters.tag ?? ''" :class="selectClass" @change="emit('update', { tag: Number(valueOf($event)) || null })">
                    <option value="">{{ t('inbox.tag_all') }}</option>
                    <option v-for="tag in tags" :key="tag.id" :value="tag.id">{{ tag.name }}</option>
                </select>
            </label>
        </div>

        <fieldset>
            <legend :class="legendClass">{{ t('inbox.filters_panel.more') }}</legend>
            <div class="grid grid-cols-2 gap-x-2 gap-y-1">
                <label v-for="f in flagOptions" :key="f" class="flex min-h-8 cursor-pointer items-center gap-2 rounded-md px-1 text-xs hover:bg-muted">
                    <input type="checkbox" class="size-4 shrink-0 rounded border-input text-primary" :checked="flagSet.has(f)" @change="toggleFlag(f)" />
                    <span class="min-w-0">{{ t(`inbox.filters.${f}`) }}</span>
                </label>
            </div>
        </fieldset>
    </div>
</template>
