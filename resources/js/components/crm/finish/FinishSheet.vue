<script setup lang="ts">
import OutcomePicker from '@/components/crm/outcomes/OutcomePicker.vue';
import ToggleSwitch from '@/components/crm/ToggleSwitch.vue';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { INBOX_OUTCOME } from '@/composables/inbox/useConversationContext';
import { useFinish } from '@/composables/inbox/useFinish';
import { useI18n } from '@/composables/useI18n';
import { useNow } from '@/composables/useNow';
import { FINISH_CASE_TYPES, enterConfirms, finishBody, isTypingTarget, type FinishTarget } from '@/lib/finish';
import { formatCount, formatSince } from '@/lib/format';
import { outcomeReady } from '@/lib/outcomes';
import { cn } from '@/lib/utils';
import type { AgentOutcome, Conversation, FinishAfter, FinishPath, SupportCaseType } from '@/types/crm';
import { useMediaQuery } from '@vueuse/core';
import { Bot, Check, FolderOpen, FolderPlus, ListChecks, MessageCircleWarning, Sparkles, X } from 'lucide-vue-next';
import { computed, inject, nextTick, ref, watch } from 'vue';

/**
 * «خلصت» (close-cycle-FINAL §0, DESIGN «CloseSheet»): one decision — the outcome, picked ahead
 * with the reason it was picked, then «خلصت». A side sheet of 440 px, a bottom sheet under 760 px.
 * Enter confirms (outside fields), Esc closes; the sheet stays open on every refusal it can fix.
 */
const props = withDefaults(
    defineProps<{
        conversation: Conversation;
        target: Extract<FinishTarget, { kind: FinishPath }>;
        /** «رجوع للبوت» from the ⋯ menu opens it on «البوت يكمّل معاها». */
        initialAfter?: FinishAfter;
        /** A supervisor ending a colleague's window: said under the title. */
        ownerName?: string | null;
        /** Her window's `awaiting_reply_since` (her own windows only: /queue/me). */
        awaitingSince?: string | null;
        /** The thread's last real message is the customer's: its text (null for a photo / file). */
        lastFromCustomer?: { body: string | null } | null;
    }>(),
    { initialAfter: 'thanks', ownerName: null, awaitingSince: null, lastFromCustomer: null },
);
const open = defineModel<boolean>('open', { required: true });

const { t, locale, dir } = useI18n();
const now = useNow();
const cycle = useFinish();
const narrow = useMediaQuery('(max-width: 759px)');

// No provider (outside the inbox) = nothing to wait for; a provider holding null = still loading.
const outcomeState = inject(INBOX_OUTCOME, null);
const outcomeLoading = computed(() => outcomeState !== null && outcomeState.value === null);
const auto = computed(() => outcomeState?.value?.auto ?? null);
const autoOrder = computed(() => outcomeState?.value?.auto_order ?? null);

const picked = ref<AgentOutcome | null>(null);
const note = ref('');
/** «البوت يكمّل» put «بتتفرج» there (Q7), not her: back on «شكر» it goes away again. */
const preselected = ref(false);
const after = ref<FinishAfter>('thanks');
const sendClosing = ref(true);
/** She touched the switch: a late «مستنية» never flips it back under her. */
const closingTouched = ref(false);
const resolveCase = ref(false);
const openCase = ref(false);
const caseType = ref<SupportCaseType>('complaint');
const sending = ref(false);
const error = ref<string | null>(null);
/** The server said she is waiting (409 customer_waiting) though the sheet did not know. */
const serverWaiting = ref<{ text: string | null } | null>(null);

const picker = ref<InstanceType<typeof OutcomePicker> | null>(null);
const primary = ref<InstanceType<typeof Button> | null>(null);

const path = computed<FinishPath>(() => props.target.kind);
const name = computed(() => props.conversation.customer?.name || `#${props.conversation.id}`);
const openCaseId = computed(() => (props.target.kind === 'window' ? (props.target.openCaseId ?? props.conversation.open_case_id) : null));

/** She is waiting for a reply (D-13): her window's flag, or her message last in the thread. Never for the lounge. */
const waitingText = computed<{ text: string | null } | null>(() => {
    if (path.value === 'lounge') return null;
    if (serverWaiting.value) return serverWaiting.value;
    if (props.lastFromCustomer) return { text: props.lastFromCustomer.body?.trim() || null };

    return props.awaitingSince ? { text: null } : null;
});
const waiting = computed(() => waitingText.value !== null);
const showWaiting = computed(() => waiting.value && after.value === 'thanks');

const subtitle = computed(() => {
    if (props.target.kind === 'window') {
        return props.target.windowNo ? t('finish.line_window', { n: formatCount(props.target.windowNo, locale.value) }) : t('finish.line_window_plain');
    }

    return path.value === 'lounge' ? t('finish.line_lounge') : t('finish.line_none');
});

/** Thanks and the 1-5 rating go together only on a window (no entry = no rating, Q14). */
const rated = computed(() => path.value === 'window');

/** The box above the outcomes: why one is picked, or what to do. Always there, so the chips never jump. */
const smart = computed<{ tone: 'brand' | 'plain'; title: string; why: string } | null>(() => {
    if (outcomeLoading.value) return null;
    const label = (value: string) => t(`outcomes.${value}`);
    if (auto.value === 'ordered') {
        const order = autoOrder.value;
        const why = order?.number
            ? t('finish.smart_ordered_why', { number: order.number, time: formatSince(order.created_at, locale.value, now.value) })
            : t('finish.smart_ordered_why_plain');

        return { tone: 'brand', title: t('finish.smart_picked', { label: label('ordered') }), why };
    }
    if (picked.value === null && auto.value === 'service') {
        return { tone: 'brand', title: t('finish.smart_picked', { label: label('service') }), why: t('finish.smart_service_why') };
    }
    if (picked.value !== null && preselected.value) {
        return { tone: 'brand', title: t('finish.smart_picked', { label: label(picked.value) }), why: t('finish.smart_bot_why') };
    }
    if (picked.value !== null) return { tone: 'plain', title: t('finish.smart_yours', { label: label(picked.value) }), why: t('finish.smart_yours_why') };

    return { tone: 'plain', title: t('finish.smart_pick'), why: path.value === 'lounge' ? t('finish.smart_pick_optional') : t('finish.smart_pick_why') };
});

/** The lounge may go without an outcome (unknown); every other end needs one unless the server records it alone. */
const ready = computed(() => {
    if (outcomeLoading.value) return false;
    if (path.value === 'lounge') return picked.value !== 'other' || note.value.trim() !== '';

    return outcomeReady(picked.value, note.value, auto.value);
});

function reset(): void {
    serverWaiting.value = null;
    picked.value = null;
    note.value = '';
    preselected.value = false;
    after.value = 'thanks';
    closingTouched.value = false;
    sendClosing.value = !waiting.value;
    resolveCase.value = false;
    openCase.value = false;
    caseType.value = 'complaint';
    error.value = null;
    sending.value = false;
    setAfter(path.value === 'lounge' ? 'thanks' : props.initialAfter);
}

function setAfter(value: FinishAfter): void {
    after.value = value;
    // Q7: «البوت يكمّل» shows «بتتفرج / هترجع» picked when nothing else is (never over an automatic one).
    if (value === 'bot' && picked.value === null && auto.value === null) {
        picked.value = 'browsing';
        preselected.value = true;
    } else if (value === 'thanks' && preselected.value) {
        picked.value = null;
        preselected.value = false;
    }
}

function setClosing(value: boolean): void {
    closingTouched.value = true;
    sendClosing.value = value;
}

// Her pick replaces ours.
watch(picked, (value, before) => {
    if (before !== undefined && value !== 'browsing') preselected.value = false;
});
// The context arrived after the sheet opened: an automatic outcome wins over the Q7 suggestion.
watch(auto, (value) => {
    if (value !== null && preselected.value) {
        picked.value = null;
        preselected.value = false;
    }
});
// She started waiting meanwhile (or the server said so): the closing message goes off unless she chose.
watch(waiting, (value) => {
    if (!closingTouched.value) sendClosing.value = !value;
});
watch(
    open,
    (isOpen) => {
        if (isOpen) reset();
    },
    { immediate: true },
);
// Another chat (or her window ended elsewhere): the sheet belongs to the one it was opened for.
watch(
    () => [props.conversation.id, props.target.kind, 'entryId' in props.target ? props.target.entryId : null],
    () => {
        if (open.value) open.value = false;
    },
);
watch(openCase, (on) => {
    if (on) resolveCase.value = false;
});

async function submit(): Promise<void> {
    if (sending.value) return;
    if (!ready.value) {
        if (!outcomeLoading.value) {
            error.value = t('outcomes.pick_first');
            picker.value?.focus();
        }

        return;
    }
    sending.value = true;
    error.value = null;
    const body = finishBody({
        path: path.value,
        entryId: 'entryId' in props.target ? props.target.entryId : null,
        picked: picked.value,
        note: note.value,
        auto: auto.value,
        after: after.value,
        sendClosing: sendClosing.value,
        waiting: waiting.value,
        resolveCaseId: resolveCase.value && openCaseId.value !== null ? openCaseId.value : null,
        openCase: openCase.value && openCaseId.value === null ? caseType.value : null,
    });
    const answer = await cycle.finish(props.conversation.id, name.value, body);
    sending.value = false;

    if (answer.ok || answer.kind === 'stale') {
        open.value = false;

        return;
    }
    if (answer.kind === 'waiting') {
        serverWaiting.value = { text: answer.lastMessage?.trim() || null };
        await nextTick();
        document.querySelector<HTMLElement>('[data-finish-waiting]')?.focus();

        return;
    }
    if (answer.kind === 'outcome') {
        error.value = answer.message;
        picker.value?.focus();

        return;
    }
    error.value = answer.kind === 'case_exists' ? t('finish.case_exists', { id: formatCount(answer.caseId ?? 0, locale.value) }) : answer.message;
}

function onKeydown(event: KeyboardEvent): void {
    if (event.defaultPrevented || event.isComposing) return;
    if (event.key === 'Enter' && !event.shiftKey && enterConfirms(event.target)) {
        event.preventDefault();
        void submit();

        return;
    }
    if (!isTypingTarget(event.target) && !event.ctrlKey && !event.metaKey && !event.altKey && picker.value?.handleKey(event.key)) {
        event.preventDefault();
        error.value = null;
    }
}

function focusPrimary(event: Event): void {
    event.preventDefault();
    void nextTick(() => (primary.value?.$el as HTMLElement | undefined)?.focus());
}

const side = computed(() => (narrow.value ? 'bottom' : dir.value === 'rtl' ? 'left' : 'right'));
const segment = (on: boolean) =>
    cn(
        'inline-flex min-h-8 flex-1 items-center justify-center gap-1.5 rounded-[6px] px-3 text-13 font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        on ? 'bg-card text-foreground shadow-e1' : 'text-fg-2 hover:text-foreground',
    );
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            :side="side"
            :class="
                cn(
                    'flex flex-col gap-0 p-0 [&>button:last-child]:hidden',
                    side === 'bottom' ? 'max-h-[88dvh] rounded-t-[20px] border-t-0' : 'w-full border-line sm:max-w-[440px]',
                )
            "
            data-finish-sheet
            @open-auto-focus="focusPrimary"
            @keydown="onKeydown"
        >
            <div class="flex items-start gap-3 border-b border-line px-5 pb-3 pt-4">
                <div class="min-w-0 flex-1">
                    <SheetTitle class="text-lg leading-7" dir="auto">{{ t('finish.title', { name }) }}</SheetTitle>
                    <SheetDescription class="mt-0.5 text-13 text-fg-2" data-finish-line>{{ subtitle }}</SheetDescription>
                    <p v-if="ownerName" class="mt-1 text-xs text-fg-2" dir="auto">{{ t('finish.not_mine', { name: ownerName }) }}</p>
                </div>
                <button
                    type="button"
                    class="-me-1.5 inline-flex size-9 shrink-0 items-center justify-center rounded-md text-fg-2 transition-colors hover:bg-sunken hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :aria-label="t('finish.close')"
                    :title="t('finish.close')"
                    data-finish-no-enter
                    @click="open = false"
                >
                    <X class="size-4" aria-hidden="true" />
                </button>
            </div>

            <div class="scrollbar-thin flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-5 py-4">
                <div
                    v-if="showWaiting"
                    class="flex gap-2.5 rounded-lg bg-warn-soft p-3 text-foreground focus:outline-none"
                    role="status"
                    tabindex="-1"
                    data-finish-waiting
                >
                    <MessageCircleWarning class="mt-0.5 size-4 shrink-0 text-warn" aria-hidden="true" />
                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="text-13 font-semibold">{{ t('finish.waiting_title') }}</p>
                        <p v-if="waitingText?.text" class="line-clamp-3 break-words text-13" dir="auto" data-finish-waiting-text>«{{ waitingText.text }}»</p>
                        <p v-else-if="lastFromCustomer" class="text-13 text-fg-2">{{ t('finish.waiting_no_text') }}</p>
                        <p class="text-xs text-fg-2">{{ t('finish.waiting_hint') }}</p>
                    </div>
                </div>

                <div v-if="smart" :class="cn('flex gap-2.5 rounded-lg p-3', smart.tone === 'brand' ? 'bg-brand-soft' : 'bg-sunken')" data-finish-smart>
                    <Sparkles v-if="smart.tone === 'brand'" class="mt-0.5 size-4 shrink-0 text-brand-ink" aria-hidden="true" />
                    <ListChecks v-else class="mt-0.5 size-4 shrink-0 text-fg-2" aria-hidden="true" />
                    <div class="min-w-0">
                        <p class="text-13 font-semibold text-foreground" dir="auto">{{ smart.title }}</p>
                        <p class="text-xs text-fg-2" dir="auto">{{ smart.why }}</p>
                    </div>
                </div>

                <section class="space-y-2" aria-labelledby="finish-outcome-label">
                    <h3 id="finish-outcome-label" class="text-13 font-semibold">{{ t('outcomes.title') }}</h3>
                    <OutcomePicker
                        ref="picker"
                        v-model="picked"
                        v-model:note="note"
                        variant="sheet"
                        :label="t('outcomes.title')"
                        :auto="auto"
                        :loading="outcomeLoading"
                    />
                    <p v-if="error" class="rounded-md bg-danger-soft px-3 py-2 text-13 text-danger" role="alert" data-finish-error>{{ error }}</p>
                </section>

                <section v-if="path !== 'lounge'" class="space-y-2" aria-labelledby="finish-after-label">
                    <h3 id="finish-after-label" class="text-13 font-semibold">{{ t('finish.after_label') }}</h3>
                    <div class="flex gap-0.5 rounded-md bg-sunken p-0.5" role="group" aria-labelledby="finish-after-label">
                        <button type="button" :class="segment(after === 'thanks')" :aria-pressed="after === 'thanks'" data-after="thanks" @click="setAfter('thanks')">
                            {{ rated ? t('finish.after_thanks') : t('finish.after_thanks_plain') }}
                        </button>
                        <button type="button" :class="segment(after === 'bot')" :aria-pressed="after === 'bot'" data-after="bot" @click="setAfter('bot')">
                            <Bot class="size-3.5" aria-hidden="true" />{{ t('finish.after_bot') }}
                        </button>
                    </div>
                    <p v-if="after === 'bot'" class="text-xs text-fg-2">{{ t('finish.after_bot_hint') }}</p>

                    <div v-else class="flex items-center gap-3 pt-1" data-finish-closing>
                        <div class="min-w-0 flex-1">
                            <p class="text-13 font-semibold">{{ rated ? t('finish.closing_label') : t('finish.closing_label_plain') }}</p>
                            <p class="text-xs text-fg-2">{{ rated ? t('finish.closing_preview') : t('finish.closing_preview_plain') }}</p>
                        </div>
                        <ToggleSwitch
                            :model-value="sendClosing"
                            :label="rated ? t('finish.closing_label') : t('finish.closing_label_plain')"
                            @update:model-value="setClosing"
                        />
                    </div>
                </section>

                <section v-if="path === 'window'" class="space-y-2 border-t border-line pt-4" data-finish-case>
                    <label v-if="openCaseId !== null" class="flex cursor-pointer items-center gap-2.5 text-13">
                        <FolderOpen class="size-4 shrink-0 text-fg-2" aria-hidden="true" />
                        <span class="min-w-0 flex-1">{{ t('finish.case_open', { id: formatCount(openCaseId, locale) }) }}</span>
                        <input v-model="resolveCase" type="checkbox" class="size-4 accent-brand" data-finish-resolve-case />
                        <span class="font-medium">{{ t('finish.case_resolved') }}</span>
                    </label>
                    <template v-else>
                        <label class="flex cursor-pointer items-center gap-2.5 text-13">
                            <input v-model="openCase" type="checkbox" class="size-4 accent-brand" data-finish-open-case />
                            <FolderPlus class="size-4 shrink-0 text-fg-2" aria-hidden="true" />
                            <span class="font-medium">{{ t('finish.case_new') }}</span>
                        </label>
                        <div v-if="openCase" class="space-y-1.5 ps-[26px]">
                            <select
                                v-model="caseType"
                                class="h-10 w-full rounded-md border border-line-strong bg-card px-3 text-sm focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand-soft"
                                :aria-label="t('finish.case_type')"
                                data-finish-case-type
                            >
                                <option v-for="type in FINISH_CASE_TYPES" :key="type" :value="type">{{ t(`cases.types.${type}`) }}</option>
                            </select>
                            <p class="text-xs text-fg-2">{{ t('finish.case_new_hint') }}</p>
                        </div>
                    </template>
                </section>
            </div>

            <div class="flex items-center gap-2 border-t border-line px-5 pb-[max(12px,env(safe-area-inset-bottom))] pt-3">
                <Button ref="primary" type="button" class="h-11 flex-1 text-sm" :loading="sending" :disabled="outcomeLoading" data-finish-confirm @click="submit">
                    <Check aria-hidden="true" />{{ t('finish.confirm') }}
                    <kbd class="ms-1 hidden rounded border border-brand-on/30 px-1 text-2xs font-normal opacity-80 md:inline" aria-hidden="true">{{ t('finish.enter_hint') }}</kbd>
                </Button>
                <Button type="button" variant="ghost" class="h-11 px-5 text-sm" data-finish-no-enter data-finish-cancel @click="open = false">{{ t('finish.cancel') }}</Button>
            </div>
        </SheetContent>
    </Sheet>
</template>
