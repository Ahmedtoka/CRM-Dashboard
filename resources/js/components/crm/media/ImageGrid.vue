<script setup lang="ts">
import MediaLightbox from '@/components/crm/media/MediaLightbox.vue';
import type { Attachment } from '@/types/crm';
import { computed, ref } from 'vue';

const props = defineProps<{ images: Attachment[] }>();

const lightboxIndex = ref<number | null>(null);
const visible = computed(() => props.images.slice(0, 4));
const extra = computed(() => Math.max(0, props.images.length - 4));

function open(index: number): void {
    lightboxIndex.value = index;
}
</script>

<template>
    <div>
        <button v-if="images.length === 1" type="button" class="block" @click="open(0)">
            <img :src="images[0].thumb_url ?? images[0].url ?? ''" :alt="images[0].original_name ?? ''" class="max-w-80 rounded-lg object-cover" />
        </button>

        <div v-else class="grid max-w-80 grid-cols-2 gap-0.5 overflow-hidden rounded-lg">
            <button
                v-for="(image, i) in visible"
                :key="image.id"
                type="button"
                class="relative aspect-square overflow-hidden"
                @click="open(i)"
            >
                <img :src="image.thumb_url ?? image.url ?? ''" :alt="image.original_name ?? ''" class="size-full object-cover" />
                <span v-if="i === 3 && extra > 0" class="absolute inset-0 flex items-center justify-center bg-black/50 text-lg font-semibold text-white">
                    +{{ extra }}
                </span>
            </button>
        </div>

        <MediaLightbox v-model:index="lightboxIndex" :items="images" />
    </div>
</template>
