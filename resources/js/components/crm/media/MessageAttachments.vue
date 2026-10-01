<script setup lang="ts">
import AudioPlayer from '@/components/crm/media/AudioPlayer.vue';
import FileChip from '@/components/crm/media/FileChip.vue';
import ImageGrid from '@/components/crm/media/ImageGrid.vue';
import MediaLightbox from '@/components/crm/media/MediaLightbox.vue';
import VideoBox from '@/components/crm/media/VideoBox.vue';
import type { ChatSkin } from '@/composables/inbox/useChatSkin';
import { galleryItemsOf, injectThreadGallery, type GalleryItem } from '@/composables/inbox/useThreadGallery';
import { useI18n } from '@/composables/useI18n';
import { useNow } from '@/composables/useNow';
import type { Attachment, Message } from '@/types/crm';
import { RotateCw } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{ attachments: Attachment[]; createdAt: string | null; group?: Message[]; retryingIds?: number[]; skin?: ChatSkin }>(),
    { retryingIds: () => [], skin: 'suite' },
);
const emit = defineEmits<{ retry: [attachment: Attachment] }>();

const { t } = useI18n();
// Ticks every 30s so a pending bubble reactively flips to "failed + retry" once it
// crosses the staleness threshold, without waiting for an unrelated re-render.
const now = useNow();

const PENDING_RETRY_MS = 10 * 60 * 1000;

function isRetrying(attachment: Attachment): boolean {
    return props.retryingIds.includes(attachment.id);
}

function isStale(attachment: Attachment): boolean {
    if (isRetrying(attachment)) return false;
    if (attachment.status === 'failed') return true;
    if (attachment.status !== 'pending' || !props.createdAt) return false;
    return now.value - Date.parse(props.createdAt) > PENDING_RETRY_MS;
}

const isStoredImage = (a: Attachment) => a.type === 'image' && a.status === 'stored';

// A run of consecutive image-only messages (spec §1.5) renders as one grid.
const images = computed(() => [...props.attachments, ...(props.group ?? []).flatMap((m) => m.attachments)].filter(isStoredImage));

// Everything else, in the message's own order: pending/failed rows (any type) show a
// spinner or retry; stored non-image rows render by type.
const rest = computed(() => props.attachments.filter((a) => !isStoredImage(a)));

// The thread gallery (one per ChatThread). Rendered outside a thread, the bubble falls
// back to a gallery of its own media so a click still opens something.
const threadGallery = injectThreadGallery();
const localIndex = ref<number | null>(null);
const localOpener = ref<HTMLElement | null>(null);
const localItems = computed<GalleryItem[]>(() =>
    threadGallery ? [] : galleryItemsOf([...props.attachments, ...(props.group ?? []).flatMap((m) => m.attachments)]),
);

function openGallery(attachment: Attachment, opener: HTMLElement): void {
    if (threadGallery) {
        threadGallery.openAt(attachment.id, opener);
        return;
    }
    const i = localItems.value.findIndex((item) => item.id === attachment.id);
    if (i === -1) return;
    localOpener.value = opener;
    localIndex.value = i;
}
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <div v-if="images.length" :class="skin === 'whatsapp' ? 'rounded-md bg-black/5 p-1 dark:bg-white/5' : undefined">
            <ImageGrid :images="images" :class="skin === 'whatsapp' ? 'overflow-hidden rounded-md' : undefined" @open="openGallery" />
        </div>

        <template v-for="attachment in rest" :key="attachment.id">
            <div
                v-if="attachment.status !== 'stored'"
                class="flex w-40 flex-col items-center justify-center gap-1 rounded-lg bg-black/10 p-3 text-2xs text-current dark:bg-white/15"
            >
                <template v-if="isStale(attachment)">
                    <span class="opacity-80">{{ t('media.download_failed') }}</span>
                    <button type="button" class="inline-flex items-center gap-1 font-medium hover:underline" @click="emit('retry', attachment)">
                        <RotateCw class="size-3" aria-hidden="true" />{{ t('common.retry') }}
                    </button>
                </template>
                <template v-else>
                    <RotateCw class="size-4 animate-spin" aria-hidden="true" />
                    <span class="opacity-80">{{ t('media.downloading') }}</span>
                </template>
            </div>

            <VideoBox v-else-if="attachment.type === 'video'" :attachment="attachment" @fullscreen="openGallery(attachment, $event)" />
            <AudioPlayer v-else-if="attachment.type === 'audio'" :src="attachment.url ?? ''" :duration-ms="attachment.duration_ms" :skin="skin" />
            <img
                v-else-if="attachment.type === 'sticker'"
                :src="attachment.thumb_url ?? attachment.url ?? ''"
                :alt="attachment.original_name ?? ''"
                width="120"
                height="120"
                loading="lazy"
                decoding="async"
                class="size-[120px] object-contain"
            />
            <FileChip v-else :name="attachment.original_name ?? ''" :size="attachment.size_bytes" :mime="attachment.mime" :href="attachment.url" />
        </template>

        <MediaLightbox v-if="!threadGallery && localItems.length" v-model:index="localIndex" :items="localItems" :opener="localOpener" />
    </div>
</template>
