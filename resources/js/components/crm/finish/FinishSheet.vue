<script setup lang="ts">
import OutcomePicker from '@/components/crm/outcomes/OutcomePicker.vue';
import ToggleSwitch from '@/components/crm/ToggleSwitch.vue';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { INBOX_OUTCOME } from '@/composables/inbox/useConversationContext';
import { useFinish } from '@/composables/inbox/useFinish';
import { useI18n } from '@/composables/useI18n';
import { useNow } from '@/composables/useNow';
import { enterConfirms, finishBody, isTypingTarget, type FinishTarget } from '@/lib/finish';
import { formatCount, formatSince } from '@/lib/format';
import { OUTCOME_CASE_TYPE, endsWithBot, outcomeReady, preselectedOutcome, sendsClosing } from '@/lib/outcomes';
import { cn } from '@/lib/utils';
import type { AgentOutcome, Conversation, FinishAfter, FinishPath, OutcomeSuggestion } from '@/types/crm';
import { useMediaQuery } from '@vueuse/core';
import { Bot, Check, FolderOpen, ListChecks, MessageCircleWarning, Sparkles, X } from 'lucide-vue-next';
import { computed, inject, nextTick, ref, watch } from 'vue';

/**
 * «خلصت» (finish-api.md, DESIGN «CloseSheet»): ONE choice that says what happened in the chat. The
 * sheet preselects it from the chat's facts (cases opened, an order, who spoke last) and says why
 * in one line; she confirms or changes it. Below it, in plain words, what the close will do (the
 * message, the case). A side sheet of 440 px, a bottom sheet under 760 px. Enter confirms
 * (outside fields), Esc closes; the sheet stays open on every refusal it can fix.
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
const suggestion = computed<OutcomeSuggestion | null>(() => outcomeState?.value?.suggested ?? null);
const autoOrder = computed(() => outcomeState?.value?.auto_order ?? null);

const picked = ref<AgentOutcome | null>(null);
const note = ref('');
/** `picked` came from the server's suggestion (or its automatic outcome), not from her hand. */
const preselected = ref(false);
/** `picked` is «بتتفرج» only because «البوت يكمّل» was chosen with nothing else known (Q7). */
const botHint = ref(false);
/** She picked something herself: a late suggestion never replaces it. */
const touched = ref(false);
const after = ref<FinishAfter>('thanks');
const closingValue = ref(true);
/** She touched the switch: the outcome's default never flips it back under her. */
const closingTouched = ref(false);
const resolveCase = ref(false);
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
/** Spam goes straight back to the bot (no thanks, no return window), whatever «بعدها» says. */
const effectiveAfter = computed<FinishAfter>(() => (endsWithBot(picked.value) ? 'bot' : after.value));
const showWaiting = computed(() => waiting.value && effectiveAfter.value === 'thanks');

/** The closing message follows the outcome (none for «ما ردّتش», none while she waits) until she flips the switch. */
const sendClosing = computed(() => (closingTouched.value ? closingValue.value : !waiting.value && sendsClosing(picked.value)));

const subtitle = computed(() => {
    if (props.target.kind === 'window') {
        return props.target.windowNo
            ? t('finish.line_window', { n: formatCount(props.target.windowNo, locale.value) })
            : t('finish.line_window_plain');
    }

    return path.value === 'lounge' ? t('finish.line_lounge') : t('finish.line_none');
});

/** Thanks and the 1-5 rating go together only on a window (no entry = no rating, Q14). */
const rated = computed(() => path.value === 'window');

const label = (value: string) => t(`outcomes.${value}`);
const caseLabel = (type: string | undefined) => (type ? t(`cases.types.${type}`) : '');

/** The one-line reason for the suggestion (finish-api.md §2). */
function whyText(s: OutcomeSuggestion): string {
    const p = s.params;
    const id = formatCount(p.case_id ?? 0, locale.value);

    switch (s.why) {
        case 'order_placed':
            return p.number
                ? t('finish.smart_why.order_placed', { number: p.number, time: formatSince(p.created_at, locale.value, now.value) })
                : t('finish.smart_why.order_placed_plain');
        case 'case_open':
            return t('finish.smart_why.case_open', { type: caseLabel(p.type), id });
        case 'case_resolved':
            return t('finish.smart_why.case_resolved', { type: caseLabel(p.type), id });
        case 'handover':
            return t('finish.smart_why.handover', { category: p.category_label ?? '' });
        case 'no_reply':
            return p.minutes
                ? t('finish.smart_why.no_reply', {
                      time: formatSince(new Date(now.value - p.minutes * 60_000).toISOString(), locale.value, now.value),
                  })
                : t('finish.smart_why.no_reply_now');
        default:
            return t('finish.smart_why.answered');
    }
}

/** The box above the outcomes: why one is picked, or what to do. Always there, so the chips never jump. */
const smart = computed<{ tone: 'brand' | 'plain'; title: string; why: string } | null>(() => {
    if (outcomeLoading.value) return null;
    const hint = t('finish.smart_why.change_hint');
    const value = picked.value;

    if (value === null) {
        return {
            tone: 'plain',
            title: t('finish.smart_pick'),
            why: path.value === 'lounge' ? t('finish.smart_pick_optional') : t('finish.smart_pick_why'),
        };
    }
    if (botHint.value) return { tone: 'brand', title: t('finish.smart_picked', { label: label(value) }), why: t('finish.smart_bot_why') };
    if (preselected.value && suggestion.value) {
        return { tone: 'brand', title: t('finish.smart_picked', { label: label(value) }), why: `${whyText(suggestion.value)} ${hint}` };
    }
    if (preselected.value) {
        const order = autoOrder.value;
        const why =
            auto.value === 'ordered' && order?.number
                ? t('finish.smart_ordered_why', { number: order.number, time: formatSince(order.created_at, locale.value, now.value) })
                : t('finish.smart_service_why');

        return { tone: 'brand', title: t('finish.smart_picked', { label: label(value) }), why };
    }
    if (suggestion.value && suggestion.value.outcome !== value) {
        return {
            tone: 'plain',
            title: t('finish.smart_yours', { label: label(value) }),
            why: t('finish.smart_changed', { label: label(suggestion.value.outcome) }),
        };
    }

    return { tone: 'plain', title: t('finish.smart_yours', { label: label(value) }), why: t('finish.smart_yours_why') };
});

/** What this close will do, in plain words (the message, the case). */
const will = computed<string[]>(() => {
    const value = picked.value;
    if (value === null || outcomeLoading.value) return [];
    const lines: string[] = [];

    if (effectiveAfter.value === 'bot') lines.push(endsWithBot(value) ? t('finish.will.nothing_spam') : t('finish.will.nothing_bot'));
    else if (path.value === 'lounge') return [];
    else if (sendClosing.value) lines.push(rated.value ? t('finish.will.thanks_rating') : t('finish.will.thanks'));
    else lines.push(value === 'no_answer' && !closingTouched.value ? t('finish.will.nothing_no_reply') : t('finish.will.nothing_off'));

    const caseType = OUTCOME_CASE_TYPE[value];
    if (path.value === 'window' && caseType) {
        lines.push(
            openCaseId.value !== null
                ? t('finish.will.case_link', { id: formatCount(openCaseId.value, locale.value) })
                : t('finish.will.case_new', { type: caseLabel(caseType) }),
        );
    }
    if (path.value === 'window' && resolveCase.value && openCaseId.value !== null)
        lines.push(t('finish.will.case_resolve', { id: formatCount(openCaseId.value, locale.value) }));

    return lines;
});

/** The lounge may go without an outcome (unknown); every other end needs one unless the server records it alone. */
const ready = computed(() => {
    if (outcomeLoading.value) return false;
    if (path.value === 'lounge') return picked.value !== 'other' || note.value.trim() !== '';

    return outcomeReady(picked.value, note.value, auto.value);
});

/** The suggestion (or the automatic outcome) is the starting point, unless she already chose or «البوت يكمّل» hinted. */
function preselect(): void {
    if (touched.value || outcomeLoading.value) return;
    const value = preselectedOutcome(suggestion.value, auto.value);

    if (value !== null) {
        picked.value = value;
        preselected.value = true;
        botHint.value = false;
    } else if (after.value === 'bot') {
        // Q7: «البوت يكمّل» shows «استفسرت وهترجع» picked when nothing else is known.
        picked.value = 'browsing';
        preselected.value = true;
        botHint.value = true;
    } else if (preselected.value) {
        picked.value = null;
        preselected.value = false;
        botHint.value = false;
    }
}

function reset(): void {
    serverWaiting.value = null;
    picked.value = null;
    note.value = '';
    preselected.value = false;
    botHint.value = false;
    touched.value = false;
    closingTouched.value = false;
    closingValue.value = true;
    resolveCase.value = false;
    error.value = null;
    sending.value = false;
    after.value = path.value === 'lounge' ? 'thanks' : props.initialAfter;
    preselect();
}

function setAfter(value: FinishAfter): void {
    after.value = value;
    preselect();
}

function setClosing(value: boolean): void {
    closingTouched.value = true;
    closingValue.value = value;
}

function onPick(value: AgentOutcome | null): void {
    picked.value = value;
    touched.value = true;
    preselected.value = false;
    botHint.value = false;
    error.value = null;
}

// The context arrived after the sheet opened: the suggestion becomes the starting point.
watch([suggestion, auto, outcomeLoading], () => {
    if (open.value) preselect();
});
watch(
    open,
    (isOpen) => {
        if (isOpen) reset();
    },
    { immediate: true },
);
// Another chat (or her window ended elsewhere): the sheet belongs to the one it was opened for.
// A string key, not an array: an array getter is "changed" on every re-render of the props, which closed the sheet by itself.
watch(
    () => `${props.conversation.id}:${props.target.kind}:${'entryId' in props.target ? props.target.entryId : ''}`,
    () => {
        if (open.value) open.value = false;
    },
);

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
        after: effectiveAfter.value,
        sendClosing: sendClosing.value,
        waiting: waiting.value,
        resolveCaseId: resolveCase.value && openCaseId.value !== null ? openCaseId.value : null,
        suggested: suggestion.value?.outcome ?? null,
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
                        <p v-if="waitingText?.text" class="line-clamp-3 break-words text-13" dir="auto" data-finish-waiting-text>
                            «{{ waitingText.text }}»
                        </p>
                        <p v-else-if="lastFromCustomer" class="text-13 text-fg-2">{{ t('finish.waiting_no_text') }}</p>
                        <p class="text-xs text-fg-2">{{ t('finish.waiting_hint') }}</p>
                    </div>
                </div>

                <div
                    v-if="smart"
                    :class="cn('flex gap-2.5 rounded-lg p-3', smart.tone === 'brand' ? 'bg-brand-soft' : 'bg-sunken')"
                    data-finish-smart
                >
                    <Sparkles v-if="smart.tone === 'brand'" class="mt-0.5 size-4 shrink-0 text-brand-ink" aria-hidden="true" />
                    <ListChecks v-else class="mt-0.5 size-4 shrink-0 text-fg-2" aria-hidden="true" />
                    <div class="min-w-0">
                        <p class="text-13 font-semibold text-foreground" dir="auto">{{ smart.title }}</p>
                        <p class="text-xs text-fg-2" dir="auto" data-finish-why>{{ smart.why }}</p>
                    </div>
                </div>

                <section class="space-y-2" aria-labelledby="finish-outcome-label">
                    <h3 id="finish-outcome-label" class="text-13 font-semibold">{{ t('outcomes.title') }}</h3>
                    <OutcomePicker
                        ref="picker"
                        :model-value="picked"
                        v-model:note="note"
                        variant="sheet"
                        :label="t('outcomes.title')"
                        :auto="preselected ? picked : null"
                        :loading="outcomeLoading"
                        @update:model-value="onPick"
                    />
                    <p v-if="error" class="rounded-md bg-danger-soft px-3 py-2 text-13 text-danger" role="alert" data-finish-error>{{ error }}</p>
                </section>

                <section v-if="will.length" class="space-y-1.5" aria-labelledby="finish-will-label" data-finish-will>
                    <h3 id="finish-will-label" class="text-13 font-semibold">{{ t('finish.will.title') }}</h3>
                    <ul class="space-y-1 text-13 text-fg-2">
                        <li v-for="line in will" :key="line" dir="auto">{{ line }}</li>
                    </ul>
                </section>

                <section v-if="path !== 'lounge'" class="space-y-2" aria-labelledby="finish-after-label">
                    <h3 id="finish-after-label" class="text-13 font-semibold">{{ t('finish.after_label') }}</h3>
                    <div class="flex gap-0.5 rounded-md bg-sunken p-0.5" role="group" aria-labelledby="finish-after-label">
                        <button
                            type="button"
                            :class="segment(effectiveAfter === 'thanks')"
                            :aria-pressed="effectiveAfter === 'thanks'"
                            data-after="thanks"
                            @click="setAfter('thanks')"
                        >
                            {{ rated ? t('finish.after_thanks') : t('finish.after_thanks_plain') }}
                        </button>
                        <button
                            type="button"
                            :class="segment(effectiveAfter === 'bot')"
                            :aria-pressed="effectiveAfter === 'bot'"
                            data-after="bot"
                            @click="setAfter('bot')"
                        >
                            <Bot class="size-3.5" aria-hidden="true" />{{ t('finish.after_bot') }}
                        </button>
                    </div>
                    <p v-if="effectiveAfter === 'bot'" class="text-xs text-fg-2">{{ t('finish.after_bot_hint') }}</p>

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

                <section v-if="path === 'window' && openCaseId !== null" class="space-y-2 border-t border-line pt-4" data-finish-case>
                    <label class="flex cursor-pointer items-center gap-2.5 text-13">
                        <FolderOpen class="size-4 shrink-0 text-fg-2" aria-hidden="true" />
                        <span class="min-w-0 flex-1">{{ t('finish.case_open', { id: formatCount(openCaseId, locale) }) }}</span>
                        <input v-model="resolveCase" type="checkbox" class="size-4 accent-brand" data-finish-resolve-case />
                        <span class="font-medium">{{ t('finish.case_resolved') }}</span>
                    </label>
                </section>
            </div>

            <div class="flex items-center gap-2 border-t border-line px-5 pb-[max(12px,env(safe-area-inset-bottom))] pt-3">
                <Button
                    ref="primary"
                    type="button"
                    class="h-11 flex-1 text-sm"
                    :loading="sending"
                    :disabled="outcomeLoading"
                    data-finish-confirm
                    @click="submit"
                >
                    <Check aria-hidden="true" />{{ t('finish.confirm') }}
                    <kbd class="ms-1 hidden rounded border border-brand-on/30 px-1 text-2xs font-normal opacity-80 md:inline" aria-hidden="true">{{
                        t('finish.enter_hint')
                    }}</kbd>
                </Button>
                <Button type="button" variant="ghost" class="h-11 px-5 text-sm" data-finish-no-enter data-finish-cancel @click="open = false">{{
                    t('finish.cancel')
                }}</Button>
            </div>
        </SheetContent>
    </Sheet>
</template>
