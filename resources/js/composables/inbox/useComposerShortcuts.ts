import { matchesKeys } from '@/composables/useShortcuts';
import type { Attachment } from '@/types/crm';
import { nextTick, ref, type Ref } from 'vue';

export type ComposerMode = 'reply' | 'note';

interface Options {
    mode: Ref<ComposerMode>;
    text: Ref<string>;
    /** @mentions picked in the note composer (Task 15) — read once on submit, cleared by the `done` callback. */
    mentions: Ref<number[]>;
    /** True while the customer window is closed / composer otherwise unusable (never gates note mode — internal notes don't depend on the channel window). */
    disabled: () => boolean;
    /** True while an upload is in flight or a quick reply is still rendering. */
    busy: () => boolean;
    /** True while a note POST is already in flight — blocks a fast double-Enter/double-click from posting twice (Task 12 fix round 1 carry-over). */
    noteBusy: () => boolean;
    lockHolderName: () => string | null;
    readyAttachments: () => Attachment[];
    quickReplyId: () => number | null;
    clearUploads: () => void;
    afterSend: () => void;
    focusEnd: () => void;
    emitSend: (body: string, attachments: Attachment[], quickReplyId: number | null) => void;
    emitSendAndResolve: (body: string, attachments: Attachment[], quickReplyId: number | null) => void;
    /** `done` fires only once the note actually persisted — the caller clears the textarea then, never before (a failed post keeps the draft). */
    emitNote: (body: string, mentions: number[], done: () => void) => void;
}

/**
 * Note-mode toggling plus the two keyboard-only sends (`mod+enter` send-and-resolve,
 * plain Enter while in note mode) — split out of Composer.vue to keep it near its
 * ~324-line budget (Task 12 ruling 7). `mod+enter` reuses the exact same guards and
 * locked-conversation confirm flow as a normal send (ruling 3); only the emitted
 * event differs.
 */
export function useComposerShortcuts(options: Options) {
    const confirming = ref(false);
    // Which action the locked-conversation confirm banner performs once
    // confirmed — set by whichever of submit()/mod+enter hit the lock guard.
    let confirmKind: 'send' | 'sendAndResolve' = 'send';

    function setMode(next: ComposerMode): void {
        options.mode.value = next;
        nextTick(options.focusEnd);
    }

    function canSubmit(): boolean {
        const hasContent = options.text.value.trim().length > 0 || options.readyAttachments().length > 0;
        return hasContent && !options.busy() && !options.disabled();
    }

    // The actual emit + cleanup, with no lock check — `run()` calls this once it
    // knows the send may proceed; `confirmSend()` calls it directly (the lock is
    // exactly what was already confirmed, re-checking it here would just reopen
    // the banner and silently drop the send — the bug this fixes).
    function emitNow(kind: 'send' | 'sendAndResolve'): void {
        const body = options.text.value.trim();
        (kind === 'send' ? options.emitSend : options.emitSendAndResolve)(body, [...options.readyAttachments()], options.quickReplyId());
        options.afterSend();
        options.clearUploads();
    }

    function run(kind: 'send' | 'sendAndResolve'): void {
        if (options.lockHolderName()) {
            confirmKind = kind;
            confirming.value = true;
            return;
        }
        emitNow(kind);
    }

    function submit(): void {
        if (canSubmit()) run('send');
    }

    function confirmSend(): void {
        confirming.value = false;
        if (canSubmit()) emitNow(confirmKind);
    }

    function cancelConfirm(): void {
        confirming.value = false;
    }

    function submitNote(): void {
        const body = options.text.value.trim();
        if (!body || options.noteBusy()) return;
        const mentions = [...options.mentions.value];
        // The textarea (and any leftover quick-reply id / mentions) is only
        // cleared once the note has actually persisted — a failed post keeps
        // the draft so nothing is silently lost.
        options.emitNote(body, mentions, () => {
            options.text.value = '';
            options.mentions.value = [];
            options.afterSend();
        });
    }

    /** Call from the textarea's keydown handler before the plain-Enter branch. Returns true once handled. */
    function handleModEnter(event: KeyboardEvent): boolean {
        if (options.mode.value !== 'reply' || !matchesKeys(event, 'mod+enter')) return false;
        event.preventDefault();
        if (canSubmit()) run('sendAndResolve');
        return true;
    }

    return { confirming, setMode, submit, confirmSend, cancelConfirm, submitNote, handleModEnter };
}
