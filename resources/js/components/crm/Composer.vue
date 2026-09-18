<script setup lang="ts">
import ComposerModeToggle from '@/components/crm/ComposerModeToggle.vue';
import MentionTextarea from '@/components/crm/MentionTextarea.vue';
import AttachmentTray from '@/components/crm/media/AttachmentTray.vue';
import VoiceRecorderBar from '@/components/crm/media/VoiceRecorderBar.vue';
import QuickReplyPicker from '@/components/crm/replies/QuickReplyPicker.vue';
import { buttonVariants } from '@/components/ui/button';
import { useAttachmentUploads } from '@/composables/inbox/useAttachmentUploads';
import { useChatSkin } from '@/composables/inbox/useChatSkin';
import { useComposerShortcuts, type ComposerMode } from '@/composables/inbox/useComposerShortcuts';
import { useQuickReplyInsertion } from '@/composables/inbox/useQuickReplyInsertion';
import { useI18n } from '@/composables/useI18n';
import { shortcutHint } from '@/composables/useShortcuts';
import { useVoiceRecorder } from '@/composables/useVoiceRecorder';
import { cn } from '@/lib/utils';
import type { Attachment, PlatformValue, QuickReply, QuickReplyCategory, RenderedQuickReply, UserRef } from '@/types/crm';
import { LoaderCircle, MessageSquareText, Mic, NotebookPen, Paperclip, SendHorizontal } from 'lucide-vue-next';
import { computed, nextTick, onMounted, onScopeDispose, ref, watch } from 'vue';

const props = defineProps<{
    disabled: boolean;
    lockHolderName: string | null;
    quickReplies: QuickReply[];
    categories: QuickReplyCategory[];
    platform: PlatformValue;
    conversationId: number;
    renderReply: (reply: QuickReply) => Promise<RenderedQuickReply>;
    /** @mentions autocomplete data source (Task 15) — teammates who can access this conversation's platform. */
    mentionable: UserRef[];
    /** True while the last note POST is still in flight — blocks a fast double-Enter/click from posting twice. */
    addingNote: boolean;
    /** The signed-in user's id — excluded from the note box's @mention suggestions. */
    meId: number;
}>();
const text = defineModel<string>({ required: true });
const mode = defineModel<ComposerMode>('mode', { default: 'reply' });
const emit = defineEmits<{
    send: [body: string, attachments: Attachment[], quickReplyId: number | null];
    sendAndResolve: [body: string, attachments: Attachment[], quickReplyId: number | null];
    note: [body: string, mentions: number[], done: () => void];
    typing: [];
}>();

const { t } = useI18n();

const textarea = ref<HTMLTextAreaElement | null>(null);
const noteInput = ref<InstanceType<typeof MentionTextarea> | null>(null);
const confirmButton = ref<HTMLButtonElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const autoStopNotice = ref(false);
const noteMentions = ref<number[]>([]);
let autoStopTimer: number | undefined;

const uploads = useAttachmentUploads(() => props.conversationId);
const { skin, classes: skinClasses } = useChatSkin(() => props.platform);
// The whatsapp accent hex lives once in useChatSkin's class map; applied via inline style
// (not an interpolated `bg-[...]` class) since Tailwind can't statically resolve a class
// name built from a JS variable.
const sendButtonClass = computed(() => (skin.value === 'whatsapp' ? 'text-white hover:opacity-90' : ''));
const sendButtonStyle = computed(() => (skin.value === 'whatsapp' ? { backgroundColor: skinClasses.value.accent } : undefined));
const iconButtonClass = cn(buttonVariants({ variant: 'ghost', size: 'icon' }), 'rounded-full text-muted-foreground hover:text-primary');

const {
    picker,
    menuOpen: quickMenuOpen,
    activeIndex: quickActiveIndex,
    pickerFromButton,
    pickerQuery,
    platformReplies,
    rendering: quickRendering,
    missing: quickMissing,
    missingMessage,
    localError: quickError,
    quickReplyId,
    closePicker,
    toggleFromButton,
    pick: pickQuickReply,
    onTextEdited,
    afterSend,
} = useQuickReplyInsertion({
    text,
    quickReplies: () => props.quickReplies,
    platform: () => props.platform,
    conversationId: () => props.conversationId,
    disabled: () => props.disabled,
    renderReply: props.renderReply,
    addExisting: (attachments) => uploads.addExisting(attachments),
    focusEnd: () => focusEnd(),
});

const composerShortcuts = useComposerShortcuts({
    mode,
    text,
    mentions: noteMentions,
    disabled: () => props.disabled,
    busy: () => uploads.busy.value || quickRendering.value,
    noteBusy: () => props.addingNote,
    lockHolderName: () => props.lockHolderName,
    readyAttachments: () => uploads.ready.value,
    quickReplyId: () => quickReplyId.value,
    clearUploads: () => uploads.clear(),
    afterSend,
    focusEnd: () => focusEnd(),
    emitSend: (body, attachments, id) => emit('send', body, attachments, id),
    emitSendAndResolve: (body, attachments, id) => emit('sendAndResolve', body, attachments, id),
    emitNote: (body, mentions, done) => emit('note', body, mentions, done),
});
const { confirming } = composerShortcuts;

// Switching conversation always drops back to reply mode (ruling: notes are per-message-in-context, not a sticky
// preference) and drops any locked-conversation confirm banner left over from the conversation being replaced.
watch(
    () => props.conversationId,
    () => {
        mode.value = 'reply';
        confirming.value = false;
        noteMentions.value = [];
    },
);

watch(confirming, (open) => {
    if (open) nextTick(() => confirmButton.value?.focus());
});

// Fix round 1, minor (b): refocus the note box once a note finishes saving (or
// once it's re-enabled for any other reason) — a `disabled` textarea loses
// focus natively, and nothing else was returning it afterwards.
watch(
    () => props.addingNote,
    (busy) => {
        if (!busy && mode.value === 'note') nextTick(focusEnd);
    },
);

// Auto-stop at the max duration behaves like a manual stop: the file lands in the
// tray and a brief notice explains why the recording ended on its own.
function onAutoStop(file: File): void {
    uploads.add([file]);
    autoStopNotice.value = true;
    window.clearTimeout(autoStopTimer);
    autoStopTimer = window.setTimeout(() => (autoStopNotice.value = false), 4000);
    nextTick(focusEnd);
}

const recorder = useVoiceRecorder(300, onAutoStop);

// A recording never follows the moderator to a different conversation.
watch(
    () => props.conversationId,
    () => recorder.cancel(),
);

onScopeDispose(() => window.clearTimeout(autoStopTimer));

const recording = computed(() => recorder.state.value === 'recording');
// Blocked while a quick reply is still rendering (ruling: the raw "/shortcut"
// must never be sendable before its render resolves).
const canSend = computed(
    () => !props.disabled && !quickRendering.value && !uploads.busy.value && (text.value.trim().length > 0 || uploads.ready.value.length > 0),
);
// Notes never depend on the customer channel window — only the text itself.
const canSendNote = computed(() => text.value.trim().length > 0);

watch(text, () => nextTick(autosize));

function autosize(): void {
    const el = textarea.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
}

function focusEnd(): void {
    if (mode.value === 'note') {
        noteInput.value?.focus();
        return;
    }
    const el = textarea.value;
    el?.focus();
    el?.setSelectionRange(el.value.length, el.value.length);
}

function submit(): void {
    if (mode.value === 'note') composerShortcuts.submitNote();
    else composerShortcuts.submit();
}

// Note mode hides the mic entirely; a recording already in progress — or still
// waiting on the mic-permission prompt — when the moderator switches mode is
// discarded rather than left in limbo (`cancel()` handles the "still starting"
// case itself by flagging the pending `getUserMedia()` to release the mic once
// it resolves).
function setMode(next: ComposerMode): void {
    if (next === 'note' && (recording.value || recorder.starting.value)) recorder.cancel();
    composerShortcuts.setMode(next);
}

function hint(id: string): string {
    const key = shortcutHint(id);
    return key ? ` (${key})` : '';
}

function onConfirmSend(): void {
    composerShortcuts.confirmSend();
    nextTick(focusEnd);
}

function onCancelConfirm(): void {
    composerShortcuts.cancelConfirm();
    nextTick(focusEnd);
}

// A picker closed from its own (button-opened) search box — Escape or the "x"
// — must return focus to the composer textarea (Task 5 carry-over fix).
function onPickerClose(): void {
    closePicker();
    nextTick(focusEnd);
}

function onKeydown(event: KeyboardEvent): void {
    // 229 is the historical IME-composition keyCode some browsers still report
    // for the commit keystroke even after `isComposing` has flipped back to false.
    if (event.isComposing || event.keyCode === 229) return;

    // While the locked-conversation confirm banner is open, only its own two
    // buttons may move the send forward — a plain Enter must never silently
    // downgrade a pending "send anyway, then resolve" to a plain send (Task 12
    // fix round 1 carry-over). Fix round 1, minor (d): explicitly prevent the
    // default too, so Enter/mod+enter never falls through to inserting a
    // newline or any other native textarea behaviour while the banner is up.
    if (confirming.value) {
        if (event.key === 'Enter') event.preventDefault();
        return;
    }

    if (quickMenuOpen.value) {
        const items = picker.value?.visible ?? [];
        const count = items.length;
        if (event.key === 'Escape') {
            event.preventDefault();
            closePicker();
            return;
        }
        if (count && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            event.preventDefault();
            quickActiveIndex.value = (quickActiveIndex.value + (event.key === 'ArrowDown' ? 1 : count - 1)) % count;
            return;
        }
        if (count && (event.key === 'Enter' || event.key === 'Tab')) {
            event.preventDefault();
            void pickQuickReply(items[quickActiveIndex.value]);
            return;
        }
    }

    if (composerShortcuts.handleModEnter(event)) return;

    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        submit();
    }
}

function onInput(event: Event): void {
    text.value = (event.target as HTMLTextAreaElement).value;
    confirming.value = false;
    onTextEdited(text.value);
    if (text.value.trim()) emit('typing');
}

function onPaste(event: ClipboardEvent): void {
    if (mode.value !== 'reply') return; // notes never take attachments — let the paste land as plain text
    const files = Array.from(event.clipboardData?.files ?? []).filter((f) => f.type.startsWith('image/'));
    if (files.length) {
        event.preventDefault();
        uploads.add(files);
    }
}

function onPick(event: Event): void {
    const input = event.target as HTMLInputElement;
    uploads.add(Array.from(input.files ?? []));
    input.value = '';
}

async function sendVoice(): Promise<void> {
    const file = await recorder.stop();
    if (file) uploads.add([file]);
    nextTick(focusEnd);
}

function onCancelRecording(): void {
    recorder.cancel();
    nextTick(focusEnd);
}

onMounted(() => {
    autosize();
    if (window.matchMedia('(min-width: 768px)').matches) textarea.value?.focus();
});

defineExpose({
    addFiles: (files: File[]) => uploads.add(files),
    openFilePicker: () => fileInput.value?.click(),
    focus: focusEnd,
    setMode,
});
</script>

<template>
    <div class="relative border-t bg-card px-3 py-2">
        <QuickReplyPicker
            v-if="quickMenuOpen"
            ref="picker"
            :items="platformReplies"
            :categories="categories"
            :query="pickerQuery"
            :active-index="quickActiveIndex"
            :searchable="pickerFromButton"
            @pick="pickQuickReply"
            @hover="quickActiveIndex = $event"
            @close="onPickerClose"
            @update:query="pickerQuery = $event"
        />

        <div
            v-if="confirming"
            role="alertdialog"
            aria-live="assertive"
            class="mb-2 flex flex-wrap items-center gap-2 rounded-md bg-warning/15 px-3 py-2 text-xs text-foreground"
            @keydown.esc.prevent="onCancelConfirm"
        >
            <span class="min-w-0 flex-1">{{ t('composer.confirm_locked', { name: lockHolderName ?? '' }) }}</span>
            <button ref="confirmButton" type="button" :class="cn(buttonVariants({ size: 'sm' }), 'h-7')" @click="onConfirmSend">{{ t('composer.send_anyway') }}</button>
            <button type="button" :class="cn(buttonVariants({ variant: 'ghost', size: 'sm' }), 'h-7')" @click="onCancelConfirm">{{ t('common.cancel') }}</button>
        </div>

        <ComposerModeToggle :mode="mode" @update:mode="setMode" />

        <p v-if="recorder.state.value === 'denied'" role="alert" class="mb-1.5 px-1 text-2xs text-warning">{{ t('media.mic_denied') }}</p>
        <p v-if="recorder.state.value === 'unsupported'" role="alert" class="mb-1.5 px-1 text-2xs text-warning">{{ t('media.mic_unsupported') }}</p>
        <p v-if="autoStopNotice" role="status" class="mb-1.5 px-1 text-2xs text-warning">{{ t('media.max_duration') }}</p>
        <p v-if="quickRendering" role="status" class="mb-1.5 px-1 text-2xs text-muted-foreground">{{ t('replies.rendering') }}</p>
        <p v-if="quickError" role="alert" class="mb-1.5 px-1 text-2xs text-destructive">{{ quickError }}</p>
        <p v-if="quickMissing.length" role="status" class="mb-1.5 px-1 text-2xs text-warning">{{ missingMessage }}</p>
        <p v-if="mode === 'note'" class="mb-1.5 px-1 text-2xs text-muted-foreground">{{ t('notes.mention_hint') }} · {{ t('notes.newline_hint') }}</p>

        <AttachmentTray v-if="mode === 'reply' && uploads.items.value.length" :items="uploads.items.value" @remove="uploads.remove" @retry="uploads.retry" />

        <div
            class="flex items-end gap-2 rounded-3xl px-3 py-1.5 focus-within:ring-2 focus-within:ring-ring"
            :class="[
                mode === 'note' ? 'border-s-4 border-[var(--note-border)] bg-[var(--note-bg)]' : 'bg-elevated',
                { 'opacity-60': disabled && mode === 'reply' },
            ]"
        >
            <template v-if="mode === 'reply'">
                <button
                    type="button"
                    :title="`${t('media.attach')}${hint('inbox.attach')}`"
                    :aria-label="t('media.attach')"
                    :class="cn(iconButtonClass, 'self-end disabled:pointer-events-none disabled:opacity-50')"
                    :disabled="disabled || recording"
                    @click="fileInput?.click()"
                >
                    <Paperclip class="size-4" aria-hidden="true" />
                </button>
                <input ref="fileInput" type="file" multiple class="hidden" @change="onPick" />

                <button
                    type="button"
                    :title="t('replies.picker_button')"
                    :aria-label="t('replies.picker_button')"
                    :aria-expanded="pickerFromButton && quickMenuOpen"
                    :class="cn(iconButtonClass, 'self-end disabled:pointer-events-none disabled:opacity-50')"
                    :disabled="disabled || recording"
                    @click="toggleFromButton()"
                >
                    <MessageSquareText class="size-4" aria-hidden="true" />
                </button>
            </template>

            <VoiceRecorderBar v-if="recording" :elapsed="recorder.elapsed.value" :max="300" @cancel="onCancelRecording" @send="sendVoice" />
            <MentionTextarea
                v-else-if="mode === 'note'"
                ref="noteInput"
                v-model="text"
                v-model:mentions="noteMentions"
                :users="mentionable"
                :rows="1"
                :disabled="addingNote"
                :me-id="meId"
                size="sm"
                :placeholder="t('composer.note_placeholder')"
                class="min-h-8 flex-1 px-1 py-1.5"
                @submit="submit"
            />
            <textarea
                v-else
                ref="textarea"
                :value="text"
                rows="1"
                dir="auto"
                :disabled="disabled"
                :placeholder="disabled ? t('composer.disabled') : t('composer.placeholder')"
                :aria-label="t('composer.placeholder')"
                aria-autocomplete="list"
                :aria-expanded="quickMenuOpen"
                :aria-controls="quickMenuOpen ? 'quick-reply-menu' : undefined"
                class="max-h-40 min-h-8 flex-1 resize-none bg-transparent px-1 py-1.5 text-sm leading-5 outline-none placeholder:text-muted-foreground focus-visible:ring-0 focus-visible:ring-offset-0 disabled:cursor-not-allowed"
                @input="onInput"
                @keydown="onKeydown"
                @paste="onPaste"
            />

            <button
                v-if="!recording && mode === 'reply'"
                type="button"
                :title="t('media.record')"
                :aria-label="t('media.record')"
                :class="cn(iconButtonClass, 'self-end disabled:pointer-events-none disabled:opacity-50')"
                :disabled="disabled || recorder.starting.value"
                @click="recorder.start()"
            >
                <Mic class="size-4" aria-hidden="true" />
            </button>

            <button
                v-if="!recording && mode === 'reply'"
                type="button"
                :class="cn(buttonVariants({ size: 'icon' }), 'shrink-0 self-end rounded-full', sendButtonClass)"
                :style="sendButtonStyle"
                :disabled="!canSend"
                :aria-label="t('composer.send')"
                @click="submit"
            >
                <SendHorizontal class="rtl-flip" />
            </button>
            <button
                v-if="mode === 'note'"
                type="button"
                :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'shrink-0 self-end gap-1')"
                :disabled="!canSendNote || addingNote"
                @click="submit"
            >
                <LoaderCircle v-if="addingNote" class="size-3.5 animate-spin" aria-hidden="true" />
                <NotebookPen v-else class="size-3.5" aria-hidden="true" />{{ t('composer.add_note') }}
            </button>
        </div>
        <p class="mt-1 hidden px-1 text-2xs text-muted-foreground md:block">{{ t('composer.hint') }}</p>
    </div>
</template>
