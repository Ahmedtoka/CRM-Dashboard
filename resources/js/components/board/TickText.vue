<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { useBoardContext } from '@/lib/board/context';
import { formatSeconds } from '@/lib/format';
import { computed } from 'vue';

/**
 * The only place of the room that reads the 1 s ticker: one text node. Counts up from `since`
 * or down to `until` (ISO, on the server's clock). `template` wraps the time, e.g. «متأخرة {time}».
 */
const props = withDefaults(defineProps<{ since?: string | null; until?: string | null; format?: 'mmss' | 'short'; template?: string | null }>(), {
    since: null,
    until: null,
    format: 'mmss',
    template: null,
});

const { t, locale } = useI18n();
const board = useBoardContext();

const text = computed(() => {
    const now = board.now.value;
    const at = Date.parse((props.since ?? props.until) || '');
    const seconds = Number.isNaN(at) ? 0 : Math.max(0, props.since !== null ? Math.floor((now - at) / 1000) : Math.round((at - now) / 1000));
    const time = props.format === 'short' ? t('board.tick.minutes', { n: Math.floor(seconds / 60) }) : formatSeconds(seconds, locale.value);

    return props.template ? props.template.replace('{time}', time) : time;
});
</script>

<template>
    <span class="num">{{ text }}</span>
</template>
