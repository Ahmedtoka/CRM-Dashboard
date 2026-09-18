import type QuickReplyPicker from '@/components/crm/replies/QuickReplyPicker.vue';
import { apiErrorMessage } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import type { Attachment, PlatformValue, QuickReply, RenderedQuickReply } from '@/types/crm';
import { computed, nextTick, ref, watch, type Ref } from 'vue';

// "/" at the start or after whitespace, followed by the shortcut being typed.
const SLASH = /(^|\s)\/([^\s/]*)$/;

// How much of a rendered body is remembered to detect "the draft was replaced
// wholesale" (minor b) — long enough to be a meaningful fingerprint, short
// enough that a small edit near the end of the message doesn't false-positive.
const ID_FINGERPRINT_LENGTH = 20;

interface Options {
    text: Ref<string>;
    quickReplies: () => QuickReply[];
    platform: () => PlatformValue;
    conversationId: () => number;
    disabled: () => boolean;
    renderReply: (reply: QuickReply) => Promise<RenderedQuickReply>;
    addExisting: (attachments: Attachment[]) => void;
    focusEnd: () => void;
}

/**
 * The "/" and "الردود الجاهزة" button quick-reply picker: matching/filtering by
 * platform, rendering a picked reply's variables + attachments, and tracking
 * the `quick_reply_id` to send with the next message (spec §2.3). Extracted
 * out of Composer.vue to keep it under the ~250-line guidance.
 */
export function useQuickReplyInsertion(options: Options) {
    const { t } = useI18n();

    const picker = ref<InstanceType<typeof QuickReplyPicker> | null>(null);
    const dismissed = ref(false);
    const activeIndex = ref(0);
    const buttonOpen = ref(false);
    const buttonQuery = ref('');
    const rendering = ref(false);
    const missing = ref<string[]>([]);
    const localError = ref<string | null>(null);
    const quickReplyId = ref<number | null>(null);

    // Guards against a stale render being applied: bumped on every pick, and
    // whenever an in-flight render is abandoned (picker closed, conversation
    // switched) — only the resolution whose token still matches gets applied.
    let requestToken = 0;
    // First ~20 chars of the last inserted body, used to detect the draft
    // being replaced wholesale (minor b) so `quickReplyId` doesn't ride along
    // with unrelated text.
    let insertedFingerprint: string | null = null;

    const slashQuery = computed(() => {
        const match = SLASH.exec(options.text.value);
        return match ? match[2].toLowerCase() : null;
    });

    const platformReplies = computed(() => options.quickReplies().filter((r) => !r.platforms?.length || r.platforms.includes(options.platform())));

    const pickerFromButton = computed(() => buttonOpen.value);

    const pickerQuery = computed<string>({
        get: () => (buttonOpen.value ? buttonQuery.value : (slashQuery.value ?? '')),
        set: (value: string) => (buttonQuery.value = value),
    });

    const menuOpen = computed(() => (slashQuery.value !== null || buttonOpen.value) && !dismissed.value && !options.disabled());

    const missingMessage = computed(() => t('replies.missing', { list: missing.value.map((key) => t('replies.variables.' + key)).join('، ') }));

    watch(slashQuery, (query) => {
        activeIndex.value = 0;
        if (query === null) dismissed.value = false;
    });

    // Never follows the moderator to a different conversation (mirrors the
    // recorder). Routed through closePicker() so an in-flight render for the
    // conversation being left is abandoned the same way a manual close does.
    watch(options.conversationId, () => {
        closePicker();
        quickReplyId.value = null;
        insertedFingerprint = null;
        missing.value = [];
        localError.value = null;
    });

    function openFromButton(): void {
        buttonOpen.value = true;
        buttonQuery.value = '';
        dismissed.value = false;
        activeIndex.value = 0;
    }

    function closePicker(): void {
        buttonOpen.value = false;
        // A pure button-mode close (no active slash context) must not leave
        // `dismissed` stuck true — nothing will ever transition slashQuery to
        // null afterwards to clear it, so "/" would stop reopening the picker.
        dismissed.value = slashQuery.value !== null;
        // Abandon any render still in flight: bump the token so its eventual
        // resolution is discarded, and drop the busy state immediately so
        // submit/pick aren't blocked waiting on a render nobody wants anymore.
        requestToken++;
        rendering.value = false;
    }

    function toggleFromButton(): void {
        if (buttonOpen.value && !dismissed.value) closePicker();
        else openFromButton();
    }

    async function pick(reply: QuickReply): Promise<void> {
        // Only one render in flight at a time — the raw "/shortcut" must
        // never be sendable while a previous pick is still resolving.
        if (rendering.value) return;

        // Captured before any state changes below: the exact "/token" text
        // (including its leading boundary) that triggered this pick, if any.
        const slashToken = slashQuery.value !== null ? (SLASH.exec(options.text.value)?.[0] ?? null) : null;

        buttonOpen.value = false;
        dismissed.value = slashQuery.value !== null;
        rendering.value = true;
        missing.value = [];
        localError.value = null;
        const myToken = ++requestToken;

        try {
            const rendered = await options.renderReply(reply);
            if (myToken !== requestToken) return; // superseded by a picker close / conversation switch — discard silently

            const text = options.text;
            if (slashToken !== null && text.value.includes(slashToken)) {
                // The token is still there (possibly no longer at the very
                // end, if the user kept typing while we awaited) — replace
                // that exact occurrence rather than blindly appending.
                text.value = text.value.replace(slashToken, rendered.body);
            } else if (text.value.trim()) {
                text.value = `${text.value}\n${rendered.body}`;
            } else {
                text.value = rendered.body;
            }
            options.addExisting(rendered.attachments);
            missing.value = rendered.missing;
            quickReplyId.value = reply.id;
            insertedFingerprint = rendered.body.slice(0, ID_FINGERPRINT_LENGTH) || null;
        } catch (e) {
            if (myToken === requestToken) localError.value = apiErrorMessage(e, t('common.error'));
        } finally {
            if (myToken === requestToken) rendering.value = false;
            nextTick(options.focusEnd);
        }
    }

    /** Called from the composer's own input handler — only a real keystroke clears the banner/id, never the programmatic insert above. */
    function onTextEdited(currentText: string): void {
        missing.value = [];
        localError.value = null;
        if (currentText.trim() === '') {
            quickReplyId.value = null;
            insertedFingerprint = null;
            return;
        }
        // The draft was replaced wholesale (e.g. selected-all-and-typed-over)
        // rather than merely edited around the inserted text: the id no
        // longer describes what's actually in the box.
        if (quickReplyId.value !== null && insertedFingerprint && !currentText.includes(insertedFingerprint)) {
            quickReplyId.value = null;
            insertedFingerprint = null;
        }
    }

    function afterSend(): void {
        quickReplyId.value = null;
        insertedFingerprint = null;
        missing.value = [];
        localError.value = null;
    }

    return {
        picker,
        menuOpen,
        activeIndex,
        pickerFromButton,
        pickerQuery,
        platformReplies,
        rendering,
        missing,
        missingMessage,
        localError,
        quickReplyId,
        openFromButton,
        closePicker,
        toggleFromButton,
        pick,
        onTextEdited,
        afterSend,
    };
}
