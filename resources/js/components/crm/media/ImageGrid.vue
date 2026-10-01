<script setup lang="ts">
import MediaBox from '@/components/crm/media/MediaBox.vue';
import { useI18n } from '@/composables/useI18n';
import { formatNumber } from '@/i18n';
import { boxStyle, fitBox, gridBox } from '@/lib/mediaSize';
import type { Attachment } from '@/types/crm';
import { computed } from 'vue';

/**
 * Images of one bubble (Task 6b): one image sized from its stored width/height (max
 * 280×360); 2 side by side (280×160); 3 as one big + two small; 4+ as a 2×2 grid with
 * «+N» on the fourth. Every box is reserved before the file loads.
 */
const props = defineProps<{ images: Attachment[] }>();
const emit = defineEmits<{ open: [attachment: Attachment, opener: HTMLElement] }>();

const single = computed(() => fitBox(props.images[0]?.width ?? null, props.images[0]?.height ?? null));
const grid = computed(() => gridBox(props.images.length));
const visible = computed(() => props.images.slice(0, 4));
const extra = computed(() => Math.max(0, props.images.length - 4));

const { locale } = useI18n();
const extraLabel = computed(() => `+${formatNumber(locale.value, extra.value)}`);

// The 3-image layout: the first image spans both rows on the start side.
const cellClass = (i: number) => (props.images.length === 3 && i === 0 ? 'row-span-2' : undefined);
// Half the grid box (minus the 2px gap) is the intrinsic size the browser reserves per cell.
const cell = computed(() => ({
    width: Math.round(grid.value.width / 2),
    height: props.images.length === 2 ? grid.value.height : Math.round(grid.value.height / 2),
}));
</script>

<template>
    <MediaBox
        v-if="images.length === 1"
        :attachment="images[0]"
        :width="single.width"
        :height="single.height"
        class="rounded-lg"
        :style="boxStyle(single)"
        @open="emit('open', images[0], $event)"
    />

    <div
        v-else
        class="grid grid-cols-2 gap-0.5 overflow-hidden rounded-lg"
        :class="images.length === 2 ? 'grid-rows-1' : 'grid-rows-2'"
        :style="boxStyle(grid)"
    >
        <MediaBox
            v-for="(image, i) in visible"
            :key="image.id"
            :attachment="image"
            :width="cell.width"
            :height="cell.height"
            fill
            :class="cellClass(i)"
            @open="emit('open', image, $event)"
        >
            <span
                v-if="i === 3 && extra > 0"
                class="absolute inset-0 flex items-center justify-center bg-black/55 text-lg font-semibold tabular-nums text-white"
                dir="ltr"
                >{{ extraLabel }}</span
            >
        </MediaBox>
    </div>
</template>
