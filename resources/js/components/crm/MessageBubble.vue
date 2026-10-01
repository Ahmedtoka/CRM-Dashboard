<script setup lang="ts">
import MessageCards from '@/components/crm/MessageCards.vue';
import MessageAttachments from '@/components/crm/media/MessageAttachments.vue';
import { skinClasses, type ChatSkin } from '@/composables/inbox/useChatSkin';
import { useI18n } from '@/composables/useI18n';
import { useInitials } from '@/composables/useInitials';
import { formatClock } from '@/lib/format';
import type { Attachment, Message } from '@/types/crm';
import { AlertCircle, Bot, Check, CheckCheck, CircleDot, Clock3, RotateCw } from 'lucide-vue-next';
import { computed, type Component } from 'vue';

const props = withDefaults(
    defineProps<{
        message: Message;
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
    }>(),
    { group: undefined, retrying: false, retryingAttachments: () => [], skin: 'suite', showAvatar: false, tail: false, customerName: null },
);
const emit = defineEmits<{ retry: [message: Message]; retryAttachment: [attachment: Attachment] }>();

const { t, locale } = useI18n();
const { getInitials } = useInitials();

// Internal notes are not bubbles: they render as NoteLine / NoteGroup (Task 6a).
type Kind = 'customer' | 'user' | 'bot' | 'system';

const kind = computed<Kind>(() => {
    const m = props.message;
    if (m.sender_type === 'system') return 'system';
    if (m.direction === 'in') return 'customer';
    return m.sender_type === 'bot' ? 'bot' : 'user';
});

// A carousel's body is only its plain-text fallback: the cards show instead (2026-09-19).
const carousel = computed(() => props.message?.cards?.type === 'generic');
const body = computed(() => (carousel.value ? '' : (props.message?.body ?? '')));

// A single sticker with no caption renders bare — no bubble chrome (spec §1.5).
const bareSticker = computed(
    () => !!props.message && !body.value && props.message.attachments.length === 1 && props.message.attachments[0].type === 'sticker',
);
const stamp = computed(() => formatClock(props.message?.created_at, locale.value));
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
                <span v-else class="inline-flex items-center gap-1"><Bot class="size-3.5 shrink-0" aria-hidden="true" />{{ t('thread.bot') }}</span>
                <span v-if="message?.is_template" class="rounded bg-black/10 px-1 opacity-90 dark:bg-white/10">{{ t('thread.template') }}</span>
            </header>

            <p v-if="body" class="whitespace-pre-wrap break-words leading-relaxed" dir="auto">
                <template v-if="kind === 'customer' && message?.payload"><CircleDot class="me-1 inline size-3.5 align-[-2px]" aria-hidden="true" /><span class="sr-only">{{ t('thread.tapped_button') }}: </span></template>{{ body }}
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

            <!-- Rich cards (branch cards, the store link): quiet, like the quick replies. -->
            <MessageCards v-if="outbound && message?.cards" :cards="message.cards" class="mt-1.5" />

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
