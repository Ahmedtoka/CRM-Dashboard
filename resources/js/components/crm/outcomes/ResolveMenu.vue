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

/**
 * «حل» for a chat outside the queue (D13): the same outcome row as the queue's «خلصت», then one close.
 * The menu stays open until the server answers: on a refusal her pick stays and the reason shows.
 */
const props = withDefaults(defineProps<{ disabled?: boolean; busy?: boolean; hint?: string }>(), { disabled: false, busy: false, hint: '' });
/** `done(null)` = resolved (the menu closes); `done(message)` = refused (the menu stays open with it). */
const emit = defineEmits<{ resolve: [payload: OutcomePayload, done: (error: string | null) => void] }>();

const { t } = useI18n();
const toast = useToast();
// No provider (outside the inbox) = nothing to wait for; a provider holding null = still loading.
const outcomeState = inject(INBOX_OUTCOME, null);
const loading = computed(() => outcomeState !== null && outcomeState.value === null);
const auto = computed(() => outcomeState?.value?.auto ?? null);
const open = ref(false);
const picked = ref<AgentOutcome | null>(null);
const note = ref('');
const sending = ref(false);
const serverError = ref<string | null>(null);
const picker = ref<InstanceType<typeof OutcomePicker> | null>(null);
const ready = computed(() => !loading.value && outcomeReady(picked.value, note.value, auto.value));

watch(open, (isOpen) => {
    if (isOpen) {
        picked.value = null;
        note.value = '';
        serverError.value = null;
    }
});

/** Sends the resolve; false when it may not go yet (no outcome, state loading, a request in flight). */
function submit(): boolean {
    if (sending.value || loading.value) return false;
    if (!ready.value) {
        toast.push(t('outcomes.pick_first'), 'error');
        picker.value?.focus();

        return false;
    }
    sending.value = true;
    serverError.value = null;
    emit('resolve', outcomePayload(picked.value, note.value, auto.value), (error) => {
        sending.value = false;
        if (error === null) open.value = false;
        else serverError.value = error;
    });

    return true;
}

function onConfirm(event: Event): void {
    // The menu closes only once the server said yes (submit's callback).
    event.preventDefault();
    submit();
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
            <OutcomePicker ref="picker" v-model="picked" v-model:note="note" :auto="auto" :loading="loading" />
            <p v-if="serverError" class="mx-2 mb-1 rounded-md bg-destructive/10 px-2 py-1.5 text-xs text-destructive" role="alert" data-resolve-error>
                {{ serverError }}
            </p>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                class="justify-center font-semibold text-primary"
                :disabled="sending || loading"
                data-resolve-confirm
                @select="onConfirm"
            >
                <LoaderCircle v-if="sending" class="animate-spin" aria-hidden="true" />
                <CheckCircle2 v-else aria-hidden="true" />{{ t('thread.header.resolve_confirm') }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
