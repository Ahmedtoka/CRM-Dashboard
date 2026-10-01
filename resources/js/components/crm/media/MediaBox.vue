<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { Attachment } from '@/types/crm';
import { computed } from 'vue';

/**
 * One sized, lazy image (Task 6b). The parent decides the box: a single image gets a
 * `boxStyle(fitBox(...))`, a grid cell fills its track (`fill`). The box carries
 * `bg-muted` so the reserved space reads as "image coming" before the file arrives.
 */
const props = withDefaults(defineProps<{ attachment: Attachment; width: number; height: number; fill?: boolean }>(), { fill: false });
const emit = defineEmits<{ open: [opener: HTMLElement] }>();

const { t } = useI18n();

const alt = computed(() => props.attachment.original_name || t('media.preview_image'));
const src = computed(() => props.attachment.thumb_url ?? props.attachment.url ?? '');

function onClick(event: MouseEvent): void {
    emit('open', event.currentTarget as HTMLElement);
}
</script>

<template>
    <button
        type="button"
        class="relative block overflow-hidden bg-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-3px] focus-visible:outline-ring"
        :class="fill ? 'size-full' : undefined"
        @click="onClick"
    >
        <img :src="src" :alt="alt" :width="width" :height="height" loading="lazy" decoding="async" class="size-full object-cover" />
        <slot />
    </button>
</template>
