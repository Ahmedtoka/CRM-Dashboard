<script setup lang="ts">
import { skinClasses, type ChatSkin } from '@/composables/inbox/useChatSkin';
import { useI18n } from '@/composables/useI18n';
import { formatDuration } from '@/lib/format';
import { Pause, Play } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';

const props = withDefaults(defineProps<{ src: string; durationMs: number | null; preload?: 'metadata' | 'none' | 'auto'; skin?: ChatSkin }>(), {
    preload: 'metadata',
    skin: 'suite',
});

// The whatsapp accent lives once in useChatSkin's class map (`accent`); applied via
// inline style since Tailwind can't statically resolve a class built from a JS variable.
// Suite inherits the bubble's own text colour instead, so it reads on any bubble colour.
const seekStyle = computed(() => (props.skin === 'whatsapp' ? { accentColor: skinClasses(props.skin).accent } : undefined));

const { t, locale } = useI18n();

const audio = ref<HTMLAudioElement | null>(null);
const playing = ref(false);
const currentMs = ref(0);
const totalMs = ref(props.durationMs ?? 0);
const speeds = [1, 1.5, 2] as const;
const speedIndex = ref(0);

function toggle(): void {
    const el = audio.value;
    if (!el) return;
    if (playing.value) el.pause();
    else void el.play();
}

function onPlay(): void {
    playing.value = true;
}

function onPause(): void {
    playing.value = false;
}

function onTimeUpdate(): void {
    if (audio.value) currentMs.value = audio.value.currentTime * 1000;
}

function onLoadedMetadata(): void {
    if (audio.value && Number.isFinite(audio.value.duration)) totalMs.value = audio.value.duration * 1000;
}

function onEnded(): void {
    playing.value = false;
    currentMs.value = 0;
}

function seek(event: Event): void {
    const el = audio.value;
    if (!el) return;
    const value = Number((event.target as HTMLInputElement).value);
    el.currentTime = value / 1000;
    currentMs.value = value;
}

function cycleSpeed(): void {
    speedIndex.value = (speedIndex.value + 1) % speeds.length;
    if (audio.value) audio.value.playbackRate = speeds[speedIndex.value];
}

onBeforeUnmount(() => audio.value?.pause());
</script>

<template>
    <div class="flex w-64 max-w-full items-center gap-2 rounded-lg border border-black/10 bg-black/10 px-2.5 py-2 dark:border-white/15 dark:bg-white/15">
        <audio
            ref="audio"
            :src="src"
            :preload="preload"
            class="hidden"
            @play="onPlay"
            @pause="onPause"
            @timeupdate="onTimeUpdate"
            @loadedmetadata="onLoadedMetadata"
            @ended="onEnded"
        />
        <button
            type="button"
            :aria-label="playing ? t('media.pause') : t('media.play')"
            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-black/10 text-current hover:bg-black/15 dark:bg-white/15 dark:hover:bg-white/25"
            @click="toggle"
        >
            <Pause v-if="playing" class="size-4" aria-hidden="true" />
            <Play v-else class="size-4" aria-hidden="true" />
        </button>
        <input
            type="range"
            class="h-1 min-w-0 flex-1"
            :class="skin === 'suite' ? 'accent-current' : undefined"
            :style="seekStyle"
            min="0"
            :max="totalMs || 1"
            step="100"
            :value="currentMs"
            :aria-label="t('media.seek')"
            @input="seek"
        />
        <span class="w-16 shrink-0 text-center text-2xs tabular-nums opacity-70">
            {{ formatDuration(currentMs, locale) }}/{{ formatDuration(totalMs, locale) }}
        </span>
        <button
            type="button"
            :aria-label="t('media.speed')"
            class="shrink-0 rounded px-1 py-0.5 text-2xs font-medium opacity-80 hover:bg-black/10 dark:hover:bg-white/15"
            @click="cycleSpeed"
        >
            {{ speeds[speedIndex] }}×
        </button>
    </div>
</template>
