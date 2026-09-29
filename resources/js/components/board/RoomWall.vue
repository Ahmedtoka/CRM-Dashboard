<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { useBoardContext } from '@/lib/board/context';
import { parseDecisionLine } from '@/lib/board/decisionLine';
import { formatClock, formatCount } from '@/lib/format';
import { computed, ref, watch } from 'vue';

const { t, locale, dir } = useI18n();
const board = useBoardContext();

const LINES = 7;

const latest = computed(() => board.decisions.value[0] ?? null);
// Read as text with a tone, never put in the page as HTML (see parseDecisionLine).
const lines = computed(() => (latest.value?.lines ?? []).slice(0, LINES).map(parseDecisionLine));
const earlier = computed(() => board.decisions.value.slice(1, 3));

const loads = computed(() =>
    board.members.value
        .filter((m) => m.user !== null && m.status !== 'left')
        .slice(0, 12)
        .map((m) => ({ id: m.id, name: m.user?.name ?? '', open: board.windowsOf(m.user?.id ?? 0).length, cap: m.cap })),
);

// A new decision: the screen's light pulses a few times.
const thinking = ref(false);
let timer: number | undefined;
watch(
    () => latest.value?.id,
    (id, before) => {
        if (id === undefined || before === undefined || id === before) return;
        thinking.value = true;
        window.clearTimeout(timer);
        timer = window.setTimeout(() => (thinking.value = false), 3000);
    },
);
</script>

<template>
    <section class="wall" :class="{ thinking }" :dir="dir" :aria-label="t('board.wall.title')">
        <div class="hd">
            <span class="pulse" aria-hidden="true" />
            <svg aria-hidden="true"><use href="#br-i-bot" /></svg>
            {{ t('board.wall.title') }}
            <time v-if="latest?.at" class="num">{{ formatClock(latest.at, locale) }}</time>
        </div>

        <p v-if="!latest" class="idle">{{ t('board.wall.idle') }}</p>
        <ol v-else :key="latest.id" aria-live="off">
            <li v-for="(segments, i) in lines" :key="i" :style="{ animationDelay: `${i * 60}ms` }">
                <span v-for="(segment, j) in segments" :key="j" :class="segment.tone">{{ segment.text }}</span>
            </li>
        </ol>

        <div v-if="loads.length > 0" class="loads">
            <span v-for="load in loads" :key="load.id">
                {{ load.name }} <b class="num">{{ formatCount(load.open, locale) }}/{{ formatCount(load.cap, locale) }}</b>
            </span>
        </div>

        <div v-if="earlier.length > 0" class="rule">
            <span v-for="decision in earlier" :key="decision.id">
                <span class="num">{{ formatClock(decision.at, locale) }}</span> · {{ decision.trigger }}
            </span>
        </div>
    </section>
</template>
