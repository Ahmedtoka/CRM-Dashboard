<script setup lang="ts">
import ProgressBar from '@/components/crm/ProgressBar.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import type { ShopifyImportState, ShopifyStage, ShopifyStageState } from '@/types/admin';
import { AlertTriangle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ importState: ShopifyImportState | null; live: boolean; resuming: boolean }>();
const emit = defineEmits<{ resume: [] }>();

const { t } = useI18n();

const STAGES: ShopifyStage[] = ['shipping', 'products', 'customers', 'orders'];

const stages = computed(() =>
    STAGES.map((stage) => ({
        stage,
        state:
            props.importState?.stages?.[stage] ??
            ({ status: 'pending', total: null, processed: 0, failed: 0, bulk_operation_id: null } as ShopifyStageState),
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
    pending: 'bg-fg-3/30',
    running: 'bg-brand',
    completed: 'bg-ok',
    failed: 'bg-danger',
};
</script>

<template>
    <section class="grid gap-3 rounded-lg border border-line bg-card p-4 text-13">
        <header class="flex items-center gap-2">
            <h2 class="text-base font-bold">{{ t('settings.shopify.import.title') }}</h2>
            <span
                class="ms-auto inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-2xs"
                :class="live ? 'bg-ok-soft text-foreground' : 'bg-sunken text-fg-2'"
            >
                <span class="size-1.5 rounded-full" :class="live ? 'bg-ok' : 'bg-fg-3'" aria-hidden="true" />
                {{ live ? t('settings.shopify.import.live') : t('settings.shopify.import.polling') }}
            </span>
        </header>

        <ul class="grid gap-2.5">
            <li v-for="{ stage, state } in stages" :key="stage" class="grid gap-1">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-medium">{{ t(`settings.shopify.import.stage.${stage}`) }}</span>
                    <span class="text-fg-2">
                        <template v-if="state.skipped">{{ t('settings.shopify.import.skipped_with_orders') }}</template>
                        <template v-else>{{ t(`settings.shopify.import.stage_status.${state.status}`) }}</template>
                        <!-- A running stage with a known total shows «n من m» on its ProgressBar below. -->
                        <template v-if="!state.skipped && (state.status === 'completed' || (state.status === 'running' && !state.total))">
                            ·
                            {{ t('settings.shopify.import.processed_only', { processed: state.processed }) }}
                        </template>
                        <span v-if="state.failed > 0" class="inline-flex items-center gap-1 text-foreground">
                            ·
                            <AlertTriangle class="size-3 text-danger" aria-hidden="true" />{{
                                t('settings.shopify.import.failed_count', { n: state.failed })
                            }}
                        </span>
                    </span>
                </div>
                <ProgressBar v-if="state.status === 'running' && state.total" :value="state.processed" :max="state.total" />
                <div
                    v-else
                    class="h-1.5 overflow-hidden rounded-full bg-sunken"
                    role="progressbar"
                    :aria-valuenow="percent(state)"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div class="h-full rounded-full transition-all" :class="barTone[state.status]" :style="{ width: percent(state) + '%' }" />
                </div>
            </li>
        </ul>

        <Button v-if="hasFailure" variant="outline" size="sm" class="w-fit" type="button" @click="emit('resume')" :loading="resuming">
            {{ t('settings.shopify.import.resume') }}
        </Button>
    </section>
</template>
