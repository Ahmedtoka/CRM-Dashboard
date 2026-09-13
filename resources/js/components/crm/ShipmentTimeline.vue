<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/format';
import type { Shipment } from '@/types/crm';
import { Truck } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ shipment: Shipment; limit?: number }>(), { limit: 4 });

const { t, locale } = useI18n();

// Newest first; the head of the list is the current status.
const events = computed(() =>
    [...(props.shipment.events ?? [])].sort((a, b) => Date.parse(b.occurred_at ?? '') - Date.parse(a.occurred_at ?? '')).slice(0, props.limit),
);

const statusLabel = (status: string | null) => (status ? t(`shipment.status.${status}`) : '—');
const delivered = computed(() => props.shipment.status === 'delivered');
const troubled = computed(() => ['failed_attempt', 'returned', 'cancelled'].includes(props.shipment.status ?? ''));
</script>

<template>
    <div class="text-xs">
        <div class="flex items-center gap-2">
            <Truck class="size-3.5 text-muted-foreground" aria-hidden="true" />
            <span class="font-medium" :class="{ 'text-emerald-700': delivered, 'text-red-700': troubled }">{{ statusLabel(shipment.status) }}</span>
            <span v-if="shipment.tracking_number" class="ms-auto truncate text-2xs text-muted-foreground" dir="ltr">{{ shipment.tracking_number }}</span>
        </div>
        <ol v-if="events.length" class="ms-1.5 mt-2 space-y-2 border-s ps-3">
            <li v-for="(event, index) in events" :key="`${event.status}-${event.occurred_at}`" class="relative">
                <span
                    class="absolute -start-[1.05rem] top-1 size-2 rounded-full"
                    :class="index === 0 ? 'bg-primary ring-2 ring-primary/20' : 'bg-slate-300 dark:bg-slate-600'"
                    aria-hidden="true"
                />
                <p :class="index === 0 ? 'font-medium text-foreground' : 'text-muted-foreground'">{{ statusLabel(event.status) }}</p>
                <p class="text-2xs text-muted-foreground">
                    <template v-if="event.description">{{ event.description }} · </template>
                    <template v-if="event.location">{{ event.location }} · </template>
                    <span class="tabular-nums">{{ formatDateTime(event.occurred_at, locale) }}</span>
                </p>
            </li>
        </ol>
    </div>
</template>
