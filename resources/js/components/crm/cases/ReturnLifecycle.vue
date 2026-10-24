<script setup lang="ts">
import StatusChip from '@/components/crm/StatusChip.vue';
import { Button } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { cairoToday, formatDate, formatDateTime } from '@/lib/format';
import type { SupportCase } from '@/types/crm';
import { type ReturnStage, type ReturnView } from '@/types/returnsShipping';
import { Check, Circle, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * A return / exchange case's lifecycle (round 2026-10-24): the steps as a timeline with who and when and whether the
 * customer was told, and the moderator's next actions with their one fact each (pickup day, cash refunded, the
 * replacement order, a cancel reason). The server validates the order of steps; this only offers what is next.
 */
const props = defineProps<{ caseId: number; view: ReturnView }>();
const emit = defineEmits<{ updated: [supportCase: SupportCase] }>();

const { t, locale } = useI18n();
const api = useApi();
const toast = useToast();

const open = ref<ReturnStage | null>(null);
const busy = ref(false);
const error = ref<string | null>(null);
const pickupDate = ref(cairoToday());
const refundAmount = ref('');
const exchangeOrderId = ref('');
const reason = ref('');

/** The path the case is on, to draw the steps that are still ahead as dim. */
const path = computed<ReturnStage[]>(() =>
    props.view.is_exchange
        ? ['requested', 'pickup_scheduled', 'picked_up', 'received', 'exchange_shipped', 'closed']
        : ['requested', 'pickup_scheduled', 'picked_up', 'received', 'closed'],
);
const reached = computed(() => new Set(props.view.timeline.map((e) => e.stage)));
const finished = computed(() => props.view.stage === 'closed' || props.view.stage === 'cancelled');
const stepsAhead = computed(() => (props.view.stage === 'cancelled' ? [] : path.value.filter((s) => !reached.value.has(s))));

const actions = computed(() =>
    props.view.next.map((stage) => ({
        stage,
        label:
            stage === 'pickup_scheduled' && props.view.stage === 'pickup_scheduled'
                ? t('return_lifecycle.actions.reschedule')
                : stage === 'picked_up' && props.view.is_exchange
                  ? t('return_lifecycle.actions.picked_up_exchange')
                  : t(`return_lifecycle.actions.${stage}`),
        danger: stage === 'cancelled',
    })),
);

function begin(stage: ReturnStage): void {
    error.value = null;
    if (stage === 'received' || stage === 'closed') {
        void submit(stage);
        return;
    }
    open.value = open.value === stage ? null : stage;
}

function payload(stage: ReturnStage): Record<string, unknown> {
    switch (stage) {
        case 'pickup_scheduled':
            return { pickup_date: pickupDate.value };
        case 'picked_up':
            return refundAmount.value === '' ? {} : { refund_amount: Number(refundAmount.value) };
        case 'exchange_shipped':
            return { exchange_order_id: exchangeOrderId.value === '' ? null : Number(exchangeOrderId.value) };
        case 'cancelled':
            return { reason: reason.value };
        default:
            return {};
    }
}

async function submit(stage: ReturnStage): Promise<void> {
    busy.value = true;
    error.value = null;
    try {
        const { data } = await api.post<{ data: SupportCase }>(`/cases/${props.caseId}/return/${stage}`, payload(stage));
        toast.push(t('return_lifecycle.done'));
        open.value = null;
        refundAmount.value = '';
        exchangeOrderId.value = '';
        reason.value = '';
        emit('updated', data.data);
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        busy.value = false;
    }
}

function detail(entry: ReturnView['timeline'][number]): string[] {
    const m = entry.meta;
    return [
        m.pickup_date ? t('return_lifecycle.pickup_on', { date: formatDate(m.pickup_date, locale.value) }) : '',
        m.refund_amount != null ? t('return_lifecycle.refunded', { amount: m.refund_amount }) : '',
        m.exchange_order_number ? t('return_lifecycle.exchange_order_line', { number: m.exchange_order_number }) : '',
        m.reason ? t('return_lifecycle.reason_line', { reason: m.reason }) : '',
    ].filter(Boolean);
}

const field = 'h-9 w-full rounded-md border border-line-strong bg-card px-2 text-13';
</script>

<template>
    <div class="space-y-3" data-return-lifecycle>
        <div class="flex flex-wrap items-center gap-2">
            <StatusChip
                :label="t(`return_lifecycle.stages.${view.stage}`)"
                :tone="view.stage === 'closed' ? 'ok' : view.stage === 'cancelled' ? 'neutral' : 'brand'"
                data-return-stage
            />
            <span class="text-xs text-fg-2">{{ t('return_lifecycle.cash_note') }}</span>
        </div>

        <ol class="ms-1.5 space-y-2.5 border-s border-line ps-4 text-13" data-return-timeline>
            <li v-for="(entry, i) in view.timeline" :key="`${entry.stage}-${entry.at}-${i}`" class="relative">
                <span class="absolute -start-[1.4rem] top-0.5 grid size-4 place-items-center rounded-full bg-brand text-white" aria-hidden="true">
                    <X v-if="entry.stage === 'cancelled'" class="size-3" />
                    <Check v-else class="size-3" />
                </span>
                <p class="font-semibold">{{ t(`return_lifecycle.stages.${entry.stage}`) }}</p>
                <p v-for="line in detail(entry)" :key="line" class="text-xs text-fg-2" dir="auto">{{ line }}</p>
                <p class="text-2xs tabular-nums text-fg-3">
                    {{ formatDateTime(entry.at, locale) }}<template v-if="entry.by"> · {{ t('return_lifecycle.by', { name: entry.by }) }}</template>
                </p>
                <p v-if="entry.notified !== null" class="text-2xs" :class="entry.notified ? 'text-ok' : 'text-warn'" data-return-notified>
                    {{ entry.notified ? t('return_lifecycle.notified') : t('return_lifecycle.not_notified') }}
                    <template v-if="entry.notify_error"> — {{ t(`return_lifecycle.notify_errors.${entry.notify_error}`) }}</template>
                </p>
            </li>
            <li v-for="s in stepsAhead" :key="`ahead-${s}`" class="relative text-fg-3">
                <span class="absolute -start-[1.4rem] top-0.5 grid size-4 place-items-center text-line-strong" aria-hidden="true"
                    ><Circle class="size-3.5"
                /></span>
                <p>{{ t(`return_lifecycle.stages.${s}`) }}</p>
            </li>
        </ol>

        <div v-if="!finished && actions.length" class="space-y-2 border-t border-line pt-3" data-return-actions>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="a in actions"
                    :key="a.stage"
                    type="button"
                    size="sm"
                    :variant="a.danger ? 'outline' : open === a.stage ? 'secondary' : 'default'"
                    :class="a.danger ? 'text-destructive' : ''"
                    :disabled="busy"
                    :data-return-action="a.stage"
                    @click="begin(a.stage)"
                >
                    {{ a.label }}
                </Button>
            </div>

            <form v-if="open" class="grid gap-2 rounded-md bg-surface-2 p-3" @submit.prevent="submit(open)">
                <label v-if="open === 'pickup_scheduled'" class="grid gap-1">
                    <span class="text-xs font-semibold text-fg-2">{{ t('return_lifecycle.fields.pickup_date') }}</span>
                    <input v-model="pickupDate" type="date" :min="cairoToday()" :class="field" dir="ltr" data-return-field="pickup_date" required />
                </label>
                <label v-if="open === 'picked_up' && !view.is_exchange" class="grid gap-1">
                    <span class="text-xs font-semibold text-fg-2">{{ t('return_lifecycle.fields.refund_amount') }}</span>
                    <input
                        v-model="refundAmount"
                        type="number"
                        min="0"
                        step="0.01"
                        :class="field"
                        dir="ltr"
                        data-return-field="refund_amount"
                        required
                    />
                </label>
                <label v-if="open === 'exchange_shipped'" class="grid gap-1">
                    <span class="text-xs font-semibold text-fg-2">{{ t('return_lifecycle.fields.exchange_order') }}</span>
                    <input v-model="exchangeOrderId" type="number" min="1" :class="field" dir="ltr" data-return-field="exchange_order_id" required />
                </label>
                <label v-if="open === 'cancelled'" class="grid gap-1">
                    <span class="text-xs font-semibold text-fg-2">{{ t('return_lifecycle.fields.reason') }}</span>
                    <input v-model="reason" type="text" maxlength="200" :class="field" data-return-field="reason" required />
                </label>
                <div class="flex gap-2">
                    <Button type="submit" size="sm" :loading="busy" data-return-confirm>{{ t('return_lifecycle.confirm') }}</Button>
                    <Button type="button" size="sm" variant="ghost" :disabled="busy" @click="open = null">{{ t('return_lifecycle.back') }}</Button>
                </div>
            </form>

            <p v-if="error" role="alert" class="text-xs text-danger" data-return-error>{{ error }}</p>
        </div>
    </div>
</template>
