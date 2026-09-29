<script setup lang="ts">
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

const RING = 106.8;

const view = computed(() => {
    const entry = props.entry;
    if (entry === null) return null;

    const left = board.silenceLeft(entry);
    const tone = board.silenceTone(entry);
    const total = board.settings.value?.silence_close_seconds ?? 300;
    const name = entry.customer?.name || t('queue.customer_fallback');
    const ticket = entry.ticket % 100000;
    const elapsed = formatSeconds(board.secondsSince(entry.delivered_at), locale.value);

    return {
        id: entry.id,
        name,
        ticket,
        outfit: outfitOf(entry),
        platform: entry.platform ?? 'facebook',
        escalation: entry.priority === 'escalation',
        tone,
        waitingReply: entry.first_reply_at === null,
        // The ring empties as the silence runs out; full while the clock is not running.
        dash: left === null ? 0 : RING * (1 - Math.min(1, left / Math.max(1, total))),
        time: left !== null && tone !== 'calm' ? formatSeconds(left, locale.value) : elapsed,
        label:
            left === null
                ? t('board.window.label', { n: props.number, name, ticket, time: elapsed })
                : t('board.window.label_silence', { n: props.number, name, ticket, time: elapsed, left: formatSeconds(left, locale.value) }),
    };
});
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
            armed: view.tone === 'warning' || view.tone === 'last',
            last: view.tone === 'last',
        }"
        :aria-label="view.label"
        :aria-pressed="selected"
        @click="$emit('select', view.id)"
    >
        <svg class="ring" viewBox="0 0 40 40" aria-hidden="true">
            <circle class="bg" cx="20" cy="20" r="17" />
            <circle class="fg" cx="20" cy="20" r="17" :stroke-dasharray="RING" :stroke-dashoffset="view.dash.toFixed(1)" />
        </svg>
        <svg class="av" :style="{ color: view.outfit.color }" aria-hidden="true"><use :href="view.outfit.alt ? '#br-g-girl2' : '#br-g-girl'" /></svg>
        <span class="pl" :style="{ background: `var(--${platformVar(view.platform)})` }" aria-hidden="true">
            <svg><use :href="`#br-i-${view.platform}`" /></svg>
        </span>
        <span class="tk num" aria-hidden="true">{{ view.ticket }}</span>
        <span class="tm num" aria-hidden="true">{{ view.time }}</span>
        <span class="nm" aria-hidden="true">{{ view.name }}</span>
    </button>
    <div v-else class="slot" :class="{ freed }" role="img" :aria-label="t('board.window.free_label', { n: number })">
        <span class="idx" aria-hidden="true">
            {{ t('queue.window', { n: number }) }}
            <b>{{ t('board.window.free') }}</b>
        </span>
    </div>
</template>
