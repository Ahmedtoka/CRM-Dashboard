<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/format';
import type { OrderTimelineEntry } from '@/types/crm';
import { computed } from 'vue';

const props = defineProps<{ entries: OrderTimelineEntry[] }>();

const { t, locale } = useI18n();

// Oldest last so the most recent event reads first, matching the rest of the app's activity lists.
const sorted = computed(() => [...props.entries].reverse());

function label(entry: OrderTimelineEntry): string {
    if (entry.source === 'shipping') {
        const status = entry.key.split('.')[1];
        return t(`shipment.status.${status}`);
    }

    const key = `orders.timeline.${entry.source}.${entry.key.replace('.', '_')}`;
    const params = {
        name: entry.label_params.name ?? '',
        user: entry.label_params.user ?? t('orders.bot'),
        amount: entry.label_params.amount ?? 0,
        currency: entry.label_params.currency ?? '',
    };
    const resolved = t(key, params);

    return resolved === key ? entry.key : resolved;
}
</script>

<template>
    <ol v-if="sorted.length" class="ms-1.5 space-y-2 border-s border-border ps-3 text-xs">
        <li v-for="(entry, index) in sorted" :key="`${entry.key}-${entry.at}`" class="relative">
            <span
                class="absolute -start-[1.05rem] top-1 size-2 rounded-full"
                :class="index === 0 ? 'bg-primary ring-2 ring-primary/20' : 'bg-border'"
                aria-hidden="true"
            />
            <p :class="index === 0 ? 'font-medium text-foreground' : 'text-muted-foreground'">{{ label(entry) }}</p>
            <p class="text-2xs tabular-nums text-muted-foreground">{{ formatDateTime(entry.at, locale) }}</p>
        </li>
    </ol>
    <p v-else class="text-xs text-muted-foreground">—</p>
</template>
