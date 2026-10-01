<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatBytes, formatDuration } from '@/lib/format';
import { boxStyle, fitBox } from '@/lib/mediaSize';
import type { Attachment } from '@/types/crm';
import { Maximize2, Play } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';

/**
 * Click-to-play video (Task 6b, R7). Until the click nothing is fetched: there is no
 * `<video>` element at all, so no `src`, no poster and no metadata request. The click
 * swaps in `<video controls autoplay preload="none" playsinline>` at the same box size.
 */
const props = defineProps<{ attachment: Attachment }>();
const emit = defineEmits<{ fullscreen: [opener: HTMLElement] }>();

const { t, locale } = useI18n();

const size = computed(() => fitBox(props.attachment.width, props.attachment.height));
const playing = ref(false);
const player = ref<HTMLVideoElement | null>(null);

const duration = computed(() => (props.attachment.duration_ms ? formatDuration(props.attachment.duration_ms, locale.value) : null));
const bytes = computed(() => (props.attachment.size_bytes ? formatBytes(props.attachment.size_bytes, locale.value) : null));

// The play button leaves the DOM on click: hand keyboard focus to the player that replaces it.
async function play(): Promise<void> {
    playing.value = true;
    await nextTick();
    player.value?.focus();
}

function openFullscreen(event: MouseEvent): void {
    player.value?.pause();
    emit('fullscreen', event.currentTarget as HTMLElement);
}
</script>

<template>
    <div class="relative overflow-hidden rounded-lg bg-black/80" :style="boxStyle(size)">
        <video
            v-if="playing"
            ref="player"
            :src="attachment.url ?? undefined"
            :width="size.width"
            :height="size.height"
            controls
            autoplay
            preload="none"
            playsinline
            class="size-full bg-black object-contain"
        />
        <button
            v-else
            type="button"
            class="group absolute inset-0 flex flex-col items-center justify-center gap-2 text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-3px] focus-visible:outline-white"
            @click="play"
        >
            <span class="sr-only">{{ t('media.play_video') }}</span>
            <span
                class="flex size-12 items-center justify-center rounded-full bg-white/90 text-black shadow-md transition-transform group-hover:scale-105"
            >
                <Play class="size-5 translate-x-px fill-current" aria-hidden="true" />
            </span>
            <span v-if="duration || bytes" class="flex items-center gap-2 text-2xs font-medium tabular-nums text-white/85">
                <span v-if="duration">{{ duration }}</span>
                <span v-if="duration && bytes" class="size-1 rounded-full bg-white/60" aria-hidden="true" />
                <span v-if="bytes">{{ bytes }}</span>
            </span>
        </button>

        <button
            type="button"
            class="absolute end-1.5 top-1.5 flex size-8 items-center justify-center rounded-full bg-black/55 text-white hover:bg-black/75 focus-visible:outline focus-visible:outline-2 focus-visible:outline-white"
            :aria-label="t('media.fullscreen')"
            :title="t('media.fullscreen')"
            @click="openFullscreen"
        >
            <Maximize2 class="size-4" aria-hidden="true" />
        </button>
    </div>
</template>
