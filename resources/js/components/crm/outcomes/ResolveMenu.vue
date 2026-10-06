<script setup lang="ts">
import OutcomePicker from '@/components/crm/outcomes/OutcomePicker.vue';
import { buttonVariants } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { INBOX_OUTCOME } from '@/composables/inbox/useConversationContext';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { outcomePayload, outcomeReady } from '@/lib/outcomes';
import { cn } from '@/lib/utils';
import type { AgentOutcome, OutcomePayload } from '@/types/crm';
import { CheckCircle2, ChevronDown, LoaderCircle } from 'lucide-vue-next';
import { computed, inject, ref, watch } from 'vue';

/** «حل» for a chat outside the queue (D13): the same outcome row as the queue's «خلصت», then one close. */
const props = withDefaults(defineProps<{ disabled?: boolean; busy?: boolean; hint?: string }>(), { disabled: false, busy: false, hint: '' });
const emit = defineEmits<{ resolve: [payload: OutcomePayload] }>();

const { t } = useI18n();
const toast = useToast();
const outcomeState = inject(INBOX_OUTCOME, null);
const auto = computed(() => outcomeState?.value?.auto ?? null);
const open = ref(false);
const picked = ref<AgentOutcome | null>(null);
const note = ref('');
const picker = ref<InstanceType<typeof OutcomePicker> | null>(null);
const ready = computed(() => outcomeReady(picked.value, note.value, auto.value));

watch(open, (isOpen) => {
    if (isOpen) {
        picked.value = null;
        note.value = '';
    }
});

function submit(): boolean {
    if (!ready.value) {
        toast.push(t('outcomes.pick_first'), 'error');
        picker.value?.focus();

        return false;
    }
    emit('resolve', outcomePayload(picked.value, note.value, auto.value));
    open.value = false;

    return true;
}

function onConfirm(event: Event): void {
    if (!submit()) event.preventDefault();
}

/** Digits 1-4 pick an outcome only while this menu is open (before reka's typeahead sees them). */
function onMenuKeydown(event: KeyboardEvent): void {
    if (event.target instanceof HTMLInputElement) return;
    if (picker.value?.handleKey(event.key)) {
        event.preventDefault();
        event.stopPropagation();
    }
}

defineExpose({
    open: (): void => {
        if (!props.disabled) open.value = true;
    },
    isOpen: (): boolean => open.value,
    submit,
});
</script>

<template>
    <DropdownMenu v-model:open="open">
        <DropdownMenuTrigger
            :class="cn(buttonVariants({ size: 'sm' }), 'h-9 shrink-0 gap-1.5 rounded-lg px-2.5 font-semibold sm:px-3')"
            :disabled="disabled"
            :title="`${t('thread.header.resolve')}${hint}`"
            :aria-label="t('thread.header.resolve')"
            data-primary-action
            data-resolve-menu
        >
            <LoaderCircle v-if="busy" class="animate-spin" aria-hidden="true" />
            <CheckCircle2 v-else aria-hidden="true" />
            <span class="hidden sm:inline">{{ t('thread.header.resolve') }}</span>
            <ChevronDown class="size-3.5 opacity-80" aria-hidden="true" />
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-72" @keydown.capture="onMenuKeydown">
            <DropdownMenuLabel class="text-xs">{{ t('thread.header.resolve_menu') }}</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <OutcomePicker ref="picker" v-model="picked" v-model:note="note" :auto="auto" />
            <DropdownMenuSeparator />
            <DropdownMenuItem class="justify-center font-semibold text-primary" data-resolve-confirm @select="onConfirm">
                <CheckCircle2 aria-hidden="true" />{{ t('thread.header.resolve_confirm') }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
