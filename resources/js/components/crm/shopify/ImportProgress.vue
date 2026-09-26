<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { ShopifyImportState, ShopifyStage, ShopifyStageState } from '@/types/admin';
import { AlertTriangle, LoaderCircle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ importState: ShopifyImportState | null; live: boolean; resuming: boolean }>();
const emit = defineEmits<{ resume: [] }>();

const { t } = useI18n();

const STAGES: ShopifyStage[] = ['shipping', 'products', 'customers', 'orders'];

const stages = computed(() =>
    STAGES.map((stage) => ({
        stage,
        state: props.importState?.stages?.[stage] ?? ({ status: 'pending', total: null, processed: 0, failed: 0, bulk_operation_id: null } as ShopifyStageState),
    })),
);

const hasFailure = computed(() => stages.value.some((s) => s.state.status === 'failed'));

// `total` counts every JSONL line of the bulk export (variants, addresses and
// line items included), so processed/total stays low for parent rows: a
// completed stage is always a full bar showing only the imported count.
function percent(state: ShopifyStageState): number {
    if (state.status === 'completed') return 100;
    if (!state.total) return 0;
    return Math.min(100, Math.round((state.processed / state.total) * 100));
}

const barTone: Record<ShopifyStageState['status'], string> = {
    pending: 'bg-muted-foreground/30',
    running: 'bg-primary',
    completed: 'bg-success',
    failed: 'bg-destructive',
};
</script>

<template>
    <section class="grid gap-3 rounded-lg bg-card p-4 text-xs shadow-card">
        <header class="flex items-center gap-2">
            <h2 class="text-sm font-semibold">{{ t('settings.shopify.import.title') }}</h2>
            <span
                class="ms-auto inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-2xs"
                :class="live ? 'bg-success/10 text-foreground' : 'bg-muted text-muted-foreground'"
            >
                <span class="size-1.5 rounded-full" :class="live ? 'bg-success' : 'bg-muted-foreground'" aria-hidden="true" />
                {{ live ? t('settings.shopify.import.live') : t('settings.shopify.import.polling') }}
            </span>
        </header>

        <ul class="grid gap-2.5">
            <li v-for="{ stage, state } in stages" :key="stage" class="grid gap-1">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-medium">{{ t(`settings.shopify.import.stage.${stage}`) }}</span>
                    <span class="text-muted-foreground">
                        <template v-if="state.skipped">{{ t('settings.shopify.import.skipped_with_orders') }}</template>
                        <template v-else>{{ t(`settings.shopify.import.stage_status.${state.status}`) }}</template>
                        <template v-if="!state.skipped && (state.status === 'running' || state.status === 'completed')">
                            ·
                            {{ state.total !== null && state.status === 'running' ? t('settings.shopify.import.processed_of_total', { processed: state.processed, total: state.total }) : t('settings.shopify.import.processed_only', { processed: state.processed }) }}
                        </template>
                        <span v-if="state.failed > 0" class="inline-flex items-center gap-1 text-foreground">
                            ·
                            <AlertTriangle class="size-3 text-destructive" aria-hidden="true" />{{ t('settings.shopify.import.failed_count', { n: state.failed }) }}
                        </span>
                    </span>
                </div>
                <div class="h-1.5 overflow-hidden rounded-full bg-elevated" role="progressbar" :aria-valuenow="percent(state)" aria-valuemin="0" aria-valuemax="100">
                    <div class="h-full rounded-full transition-all" :class="barTone[state.status]" :style="{ width: percent(state) + '%' }" />
                </div>
            </li>
        </ul>

        <button
            v-if="hasFailure"
            type="button"
            class="inline-flex h-8 w-fit items-center gap-1.5 rounded-md border border-border px-2.5 font-medium hover:bg-muted disabled:opacity-50"
            :disabled="resuming"
            @click="emit('resume')"
        >
            <LoaderCircle v-if="resuming" class="size-3.5 animate-spin" aria-hidden="true" />
            {{ t('settings.shopify.import.resume') }}
        </button>
    </section>
</template>
