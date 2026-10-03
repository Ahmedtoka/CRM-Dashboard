<script setup lang="ts">
import TickRing from '@/components/board/TickRing.vue';
import TickText from '@/components/board/TickText.vue';
import { useI18n } from '@/composables/useI18n';
import { useBoardContext } from '@/lib/board/context';
import { outfitOf, platformVar } from '@/lib/board/state';
import { formatSeconds } from '@/lib/format';
import type { QueueEntry } from '@/types/crm';
import { computed } from 'vue';

const props = defineProps<{
    /** Her customer, or null for a free window. */
    entry: QueueEntry | null;
    /** The window's number at the desk, from 1. */
    number: number;
    selected: boolean;
    /** It just became free. */
    freed: boolean;
    /** Her customer is still walking over from the lounge. */
    arriving: boolean;
}>();
defineEmits<{ select: [entryId: number] }>();

const { t, locale } = useI18n();
const board = useBoardContext();

// The clocks change these only at their thresholds; a computed that keeps its value does not
// re-render the window, so the window does not tick: its numbers are TickText leaves.
const tone = computed(() => (props.entry === null ? 'none' : board.silenceTone(props.entry)));
const handing = computed(() => props.entry !== null && board.handoffLeft(props.entry) !== null);

const view = computed(() => {
    const entry = props.entry;
    if (entry === null) return null;

    const name = entry.customer?.name || t('queue.customer_fallback');
    const ticket = entry.ticket % 100000;
    // She waits for the moderator past the apology: orange, counting down to the hand-off.
    const overdue = entry.reply_overdue === true;
    const silenceUntil = board.deadline(entry, 'silence');
    const handoffUntil = board.deadline(entry, 'handoff');
    // A snapshot for the label (read without the ticker); the text on the window ticks.
    const now = board.serverNow();
    const left = (iso: string | null) => formatSeconds(Math.max(0, Math.round((Date.parse(iso ?? '') - now) / 1000)) || 0, locale.value);
    const elapsed = formatSeconds(Math.max(0, Math.floor((now - (Date.parse(entry.delivered_at ?? '') || now)) / 1000)), locale.value);

    return {
        id: entry.id,
        name,
        ticket,
        outfit: outfitOf(entry),
        platform: entry.platform ?? 'facebook',
        escalation: entry.priority === 'escalation',
        overdue,
        openCase: entry.open_case_id,
        waitingReply: entry.first_reply_at === null,
        silenceUntil,
        handoffUntil,
        delivered: entry.delivered_at,
        label:
            overdue && handoffUntil !== null
                ? t('board.window.label_overdue', { n: props.number, name, ticket, left: left(handoffUntil) })
                : silenceUntil === null
                  ? t('board.window.label', { n: props.number, name, ticket, time: elapsed })
                  : t('board.window.label_silence', { n: props.number, name, ticket, time: elapsed, left: left(silenceUntil) }),
    };
});

/** The window's timer: the hand-off chip carries its own clock; a running silence counts down once it matters. */
const counting = computed(() => tone.value !== 'calm' && tone.value !== 'none');
const total = computed(() => board.settings.value?.silence_close_seconds ?? 300);
const handoffTemplate = computed(() => t('board.window.handoff', { time: '{time}' }));
</script>

<template>
    <button
        v-if="view"
        type="button"
        class="slot on"
        :class="{
            sel: selected,
            arriving,
            esc: view.escalation,
            wait: view.waitingReply,
            armed: tone === 'warning' || tone === 'last',
            last: tone === 'last',
            overdue: view.overdue,
        }"
        :aria-label="view.openCase ? `${view.label} · ${t('queue.open_case', { id: view.openCase })}` : view.label"
        :aria-pressed="selected"
        @click="$emit('select', view.id)"
    >
        <TickRing :until="view.silenceUntil" :total="total" />
        <svg class="av" :style="{ color: view.outfit.color }" aria-hidden="true"><use :href="view.outfit.alt ? '#br-g-girl2' : '#br-g-girl'" /></svg>
        <span class="pl" :style="{ background: `var(--${platformVar(view.platform)})` }" aria-hidden="true">
            <svg><use :href="`#br-i-${view.platform}`" /></svg>
        </span>
        <span class="tk num" aria-hidden="true">{{ view.ticket }}</span>
        <span class="tm" aria-hidden="true">
            <TickText v-if="counting && view.silenceUntil" :until="view.silenceUntil" />
            <TickText v-else :since="view.delivered" />
        </span>
        <span class="nm" aria-hidden="true">{{ view.name }}</span>
        <span v-if="view.openCase" class="chip case num" aria-hidden="true">{{ t('board.window.case', { id: view.openCase }) }}</span>
        <span v-if="view.overdue && handing && view.handoffUntil" class="chip handoff" :class="{ stacked: view.openCase }" aria-hidden="true">
            <TickText :until="view.handoffUntil" :template="handoffTemplate" />
        </span>
    </button>
    <div v-else class="slot" :class="{ freed }" role="img" :aria-label="t('board.window.free_label', { n: number })">
        <span class="idx" aria-hidden="true">
            {{ t('queue.window', { n: number }) }}
            <b>{{ t('board.window.free') }}</b>
        </span>
    </div>
</template>
