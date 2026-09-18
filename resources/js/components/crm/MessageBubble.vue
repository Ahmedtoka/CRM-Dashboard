<script setup lang="ts">
import MessageAttachments from '@/components/crm/media/MessageAttachments.vue';
import { skinClasses, type ChatSkin } from '@/composables/inbox/useChatSkin';
import { useI18n } from '@/composables/useI18n';
import { useInitials } from '@/composables/useInitials';
import { formatClock } from '@/lib/format';
import type { Attachment, Message, Note, UserRef } from '@/types/crm';
import { AlertCircle, Check, CheckCheck, Clock3, RotateCw } from 'lucide-vue-next';
import { computed, type Component } from 'vue';

const props = withDefaults(
    defineProps<{
        message?: Message;
        note?: Note;
        group?: Message[];
        platformColor: string;
        retrying?: boolean;
        retryingAttachments?: number[];
        skin?: ChatSkin;
        /** First message of an incoming sender run (suite skin only) — shows the avatar. */
        showAvatar?: boolean;
        /** First message of a run — gets the bubble tail (whatsapp skin only). */
        tail?: boolean;
        /** Customer display name, for the suite-skin run avatar's initials. */
        customerName?: string | null;
        /** Roster used to resolve a note's `mentions` ids to `@name` text for highlighting (Task 15). */
        mentionable?: UserRef[];
    }>(),
    { skin: 'suite', showAvatar: false, tail: false, customerName: null, mentionable: () => [] },
);
const emit = defineEmits<{ retry: [message: Message]; retryAttachment: [attachment: Attachment] }>();

const { t, locale } = useI18n();
const { getInitials } = useInitials();

type Kind = 'customer' | 'user' | 'bot' | 'system' | 'note';

const kind = computed<Kind>(() => {
    if (props.note || !props.message) return 'note';
    const m = props.message;
    if (m.sender_type === 'system') return 'system';
    if (m.direction === 'in') return 'customer';
    return m.sender_type === 'bot' ? 'bot' : 'user';
});

const body = computed(() => props.note?.body ?? props.message?.body ?? '');

// @mentions in a note bubble are highlighted as plain text spans (never v-html) —
// only tokens that match one of THIS note's actual mentioned users, resolved
// against the mentionable roster, not just anything shaped like "@word". Fix
// round 1, ruling 3: a fixed "@word (word)?" guess regex missed any mention
// whose name has 3+ words (or trailing punctuation right after the name), so
// the highlight regex is now built directly from this note's own mentioned
// names instead — escaped, longest-first (so "Sara Ahmed" wins over the
// shorter "Sara" alternative at the same position), joined with `|`.
function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

const mentionNames = computed<string[]>(() => {
    const ids = props.note?.mentions ?? [];
    if (!ids.length) return [];
    return ids.map((id) => props.mentionable.find((u) => u.id === id)?.name).filter((name): name is string => !!name);
});
const mentionPattern = computed<RegExp | null>(() => {
    if (!mentionNames.value.length) return null;
    const alternatives = [...mentionNames.value].sort((a, b) => b.length - a.length).map(escapeRegExp);
    return new RegExp(`(@(?:${alternatives.join('|')}))`, 'u');
});
const bodyParts = computed<Array<{ text: string; mention: boolean }>>(() => {
    const pattern = mentionPattern.value;
    if (!pattern) return [{ text: body.value, mention: false }];
    const mentionTokens = new Set(mentionNames.value.map((name) => `@${name}`));
    return body.value.split(pattern).map((part) => ({ text: part, mention: mentionTokens.has(part) }));
});
// A single sticker with no caption renders bare — no bubble chrome (spec §1.5).
const bareSticker = computed(
    () => !!props.message && !body.value && props.message.attachments.length === 1 && props.message.attachments[0].type === 'sticker',
);
const stamp = computed(() => formatClock(props.note?.created_at ?? props.message?.created_at, locale.value));
const outbound = computed(() => kind.value === 'user' || kind.value === 'bot');
const failed = computed(() => props.message?.status === 'failed');

const statusIcons: Record<string, Component> = { queued: Clock3, sent: Check, delivered: CheckCheck, read: CheckCheck, failed: AlertCircle };
const statusIcon = computed(() => (props.message?.status ? statusIcons[props.message.status] : null));

const classes = computed(() => skinClasses(props.skin));

// WhatsApp bubbles are pill-shaped with a tail on the first message of a run; suite
// bubbles are simple rounded rectangles and use an avatar for grouping instead.
const shapeClass = computed(() => {
    if (props.skin === 'whatsapp') {
        // A failed outgoing bubble's body drops to `bg-card` (see `bubbleBgClass` below) —
        // the tail must follow, or it still paints the old `--wa-out` green/teal, a colour
        // that no longer appears anywhere else on the bubble. `border-t-card` is a real
        // Tailwind color utility (registered in tailwind.config.js), not an arbitrary value.
        const outTailColor = failed.value ? 'before:border-t-card' : 'before:border-t-[var(--wa-out)]';
        return [
            'rounded-lg shadow-sm relative',
            props.tail && !outbound.value && 'before:absolute before:top-0 before:-start-2 before:border-8 before:border-transparent before:border-t-[var(--wa-in)]',
            props.tail && outbound.value && ['before:absolute before:top-0 before:-end-2 before:border-8 before:border-transparent', outTailColor],
        ];
    }
    return 'rounded-2xl';
});

// A failed outgoing message never renders on its skin's normal bubble colour — red error
// text/icon on top of a blue (suite) or dark-teal (whatsapp, checked: ~1.7:1 in dark mode)
// bubble is unreadable, so it drops to a neutral surface instead, in both skins.
const bubbleBgClass = computed(() => {
    if (failed.value && outbound.value) return 'bg-card text-foreground';
    return outbound.value ? classes.value.out : classes.value.in;
});

// Read ticks must never be `classes.tickRead` (== "text-primary", invisible on the blue
// suite bubble) unless the skin already accounts for that (whatsapp's own read-tick
// colour, or the neutral failed surface, always readable via text-destructive).
const tickToneClass = computed(() => {
    const status = props.message?.status;
    if (!status) return '';
    if (status === 'failed') return 'text-destructive';
    if (status === 'read') return classes.value.tickRead;
    return props.skin === 'suite' ? 'text-primary-foreground/60' : '';
});

// WhatsApp never groups by avatar (it uses the tail instead) — showing one anyway would
// push the first bubble of a run 36px to the side for no reason.
const showRunAvatar = computed(() => props.skin === 'suite' && props.showAvatar && kind.value === 'customer');
const runIndent = computed(() => props.skin === 'suite' && kind.value === 'customer' && !props.showAvatar && !bareSticker.value);
</script>

<template>
    <div v-if="kind === 'system'" class="flex justify-center py-1">
        <p class="max-w-[85%] rounded-full bg-elevated px-3 py-1 text-center text-2xs text-muted-foreground">
            {{ body }} <span class="tabular-nums opacity-70">· {{ stamp }}</span>
        </p>
    </div>

    <div v-else-if="kind === 'note'" class="mx-auto w-full max-w-2xl rounded-lg border-s-4 border-[var(--note-border)] bg-[var(--note-bg)] px-3 py-2 text-sm text-foreground">
        <header class="mb-1 flex items-center gap-1.5 text-2xs font-medium opacity-80">
            <span>📝 {{ t('thread.note') }}<template v-if="note?.user"> · {{ note.user.name }}</template> · {{ stamp }}</span>
        </header>
        <p class="whitespace-pre-wrap break-words leading-relaxed" dir="auto">
            <template v-for="(part, index) in bodyParts" :key="index">
                <span v-if="part.mention" class="font-semibold text-primary">{{ part.text }}</span>
                <template v-else>{{ part.text }}</template>
            </template>
        </p>
    </div>

    <div v-else class="flex items-end gap-2" :class="outbound ? 'justify-end' : 'justify-start'">
        <span
            v-if="showRunAvatar"
            class="mb-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-elevated text-2xs font-semibold text-muted-foreground"
            aria-hidden="true"
        >
            {{ getInitials(customerName || undefined) || '?' }}
        </span>

        <article
            class="max-w-[min(80%,36rem)] text-sm"
            :class="[bareSticker ? undefined : ['px-3 py-2', shapeClass, bubbleBgClass], failed && 'border border-destructive/40', runIndent && 'ms-9']"
        >
            <header v-if="(kind === 'user' || kind === 'bot') && !bareSticker" class="mb-1 flex items-center gap-1.5 text-2xs font-semibold opacity-80">
                <span
                    v-if="kind === 'user'"
                    :class="skin === 'whatsapp' ? 'rounded px-1.5 py-px text-white' : ''"
                    :style="skin === 'whatsapp' ? { backgroundColor: message?.user?.color || '#64748b' } : undefined"
                >
                    {{ message?.user?.name }}
                </span>
                <span v-else>🤖 {{ t('thread.bot') }}</span>
                <span v-if="message?.is_template" class="rounded bg-black/10 px-1 opacity-90 dark:bg-white/10">{{ t('thread.template') }}</span>
            </header>

            <p v-if="body" class="whitespace-pre-wrap break-words leading-relaxed" dir="auto">
                <span v-if="kind === 'customer' && message?.payload" aria-hidden="true">🔘 </span>{{ body }}
            </p>

            <!-- The quick replies the customer sees. Solid light chips so they stay readable
                 on every bubble colour (Messenger blue, WhatsApp green, bot, dark mode). -->
            <div v-if="outbound && message?.buttons?.length" class="mt-1.5 flex flex-wrap gap-1">
                <span
                    v-for="b in message.buttons"
                    :key="b.payload"
                    class="rounded-full bg-white/20 px-1.5 py-px text-2xs leading-4 opacity-80 ring-1 ring-white/30 dark:bg-black/20 dark:ring-white/15"
                    >{{ b.title }}</span
                >
            </div>

            <MessageAttachments
                v-if="message?.attachments?.length"
                :attachments="message.attachments"
                :group="group"
                :created-at="message.created_at"
                :retrying-ids="retryingAttachments ?? []"
                :skin="skin"
                @retry="emit('retryAttachment', $event)"
            />

            <span class="float-end ms-2 mt-1 inline-flex items-center gap-0.5 text-[10px] leading-none opacity-70">
                <span class="tabular-nums">{{ stamp }}</span>
                <component
                    :is="statusIcon"
                    v-if="kind === 'user' && statusIcon"
                    class="size-3"
                    :class="tickToneClass"
                    role="img"
                    :aria-label="t(`thread.status.${message?.status}`)"
                />
            </span>

            <div v-if="failed && message" class="clear-both mt-1.5 flex items-center gap-2 border-t border-destructive/30 pt-1.5 text-2xs text-destructive">
                <span class="min-w-0 flex-1">{{ message.error || t('thread.failed') }}</span>
                <button
                    type="button"
                    class="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 font-medium hover:bg-destructive/10 disabled:opacity-50"
                    :disabled="retrying"
                    @click="emit('retry', message)"
                >
                    <RotateCw class="size-3" :class="{ 'animate-spin': retrying }" aria-hidden="true" />{{ t('common.retry') }}
                </button>
            </div>
        </article>
    </div>
</template>
