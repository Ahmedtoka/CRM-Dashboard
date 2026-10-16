<script setup lang="ts">
import IconAction from '@/components/crm/IconAction.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import type { CallOutcome, ConfirmationRow } from '@/types/orderConfirmations';
import { MessageSquareReply, PhoneOff, ThumbsUp, X } from 'lucide-vue-next';
import { ref } from 'vue';

/**
 * The customer's answer as staff heard it (spec 2026-10-16): confirmed, cancelled or no answer. Inline icons on the call
 * list; `menu` folds them into one row menu for an answer she gave in her own words (a `sent` or edit-request row).
 */
const props = withDefaults(defineProps<{ confirmationId: number; menu?: boolean }>(), { menu: false });
const emit = defineEmits<{ recorded: [row: ConfirmationRow] }>();

const { t } = useI18n();
const api = useApi();
const toast = useToast();
const busy = ref<CallOutcome | null>(null);

const ACTIONS = [
    { outcome: 'confirmed', icon: ThumbsUp, variant: 'primary' },
    { outcome: 'cancelled', icon: X, variant: 'destructive' },
    { outcome: 'unreachable', icon: PhoneOff, variant: 'ghost' },
] as const;

async function record(outcome: CallOutcome): Promise<void> {
    busy.value = outcome;
    try {
        const { data } = await api.post<{ data: ConfirmationRow }>(`/orders/confirmations/${props.confirmationId}/call`, { outcome });
        toast.push(t('order_confirmations.call.done', { outcome: t(`order_confirmations.call_outcomes.${outcome}`) }));
        emit('recorded', data.data);
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <DropdownMenu v-if="menu">
        <DropdownMenuTrigger as-child>
            <IconAction
                :icon="MessageSquareReply"
                size="sm"
                :label="t('order_confirmations.call.record')"
                :loading="busy !== null"
                data-record-menu
            />
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuLabel class="text-xs">{{ t('order_confirmations.call.record') }}</DropdownMenuLabel>
            <DropdownMenuItem v-for="a in ACTIONS" :key="a.outcome" :disabled="busy !== null" :data-call="a.outcome" @select="record(a.outcome)">
                <component :is="a.icon" class="size-4" aria-hidden="true" />{{ t(`order_confirmations.call.${a.outcome}`) }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
    <span v-else class="inline-flex items-center gap-0.5" role="group" :aria-label="t('order_confirmations.call.group')" @click.stop>
        <IconAction
            v-for="a in ACTIONS"
            :key="a.outcome"
            :icon="a.icon"
            :variant="a.variant"
            size="sm"
            :label="t(`order_confirmations.call.${a.outcome}`)"
            :loading="busy === a.outcome"
            :disabled="busy !== null"
            :data-call="a.outcome"
            @click="record(a.outcome)"
        />
    </span>
</template>
