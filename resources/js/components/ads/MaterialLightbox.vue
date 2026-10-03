<script setup lang="ts">
/** Gallery of a material's files: images full size, videos in a native player (metadata only until played). */
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { useI18n } from '@/composables/useI18n';
import { isVideo } from '@/lib/adsMaterials';
import type { MaterialFile } from '@/types/ads';
import { ChevronLeft, ChevronRight, Download, Play } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ open: boolean; title: string; files: MaterialFile[] }>();
const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const { t } = useI18n();
const index = ref(0);
watch(
    () => props.open,
    (open) => open && (index.value = 0),
);

const current = computed<MaterialFile | null>(() => props.files[index.value] ?? null);
const step = (d: number) => {
    const n = props.files.length;
    if (n) index.value = (index.value + d + n) % n;
};

function onKey(e: KeyboardEvent): void {
    // Physical arrows follow the reading direction: in RTL the left arrow is "next".
    // A focused video keeps its own arrows (seek); they do not also change the file.
    if (e.target instanceof HTMLVideoElement) return;
    const rtl = document.documentElement.dir === 'rtl';
    if (e.key === 'ArrowRight') step(rtl ? -1 : 1);
    if (e.key === 'ArrowLeft') step(rtl ? 1 : -1);
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-h-[95svh] overflow-y-auto sm:max-w-3xl" @keydown="onKey">
            <DialogTitle class="truncate pe-6 text-base" dir="auto">{{ title }}</DialogTitle>
            <DialogDescription class="text-xs">{{ t('ads.materials.gallery.count', { n: index + 1, total: files.length }) }}</DialogDescription>

            <div v-if="current" class="relative flex items-center justify-center overflow-hidden rounded-lg bg-black/90">
                <video
                    v-if="isVideo(current.mime)"
                    :key="current.id"
                    :src="current.url"
                    :poster="current.thumb_url ?? undefined"
                    controls
                    preload="metadata"
                    class="max-h-[65svh] w-full object-contain"
                />
                <img v-else :key="current.id" :src="current.url" :alt="current.original_name ?? title" class="max-h-[65svh] w-auto object-contain" />

                <template v-if="files.length > 1">
                    <button
                        type="button"
                        class="absolute start-2 top-1/2 inline-flex size-9 -translate-y-1/2 items-center justify-center rounded-full bg-background/80 text-foreground shadow hover:bg-background"
                        :aria-label="t('ui.prev')"
                        @click="step(-1)"
                    >
                        <ChevronLeft class="rtl-flip size-5" aria-hidden="true" />
                    </button>
                    <button
                        type="button"
                        class="absolute end-2 top-1/2 inline-flex size-9 -translate-y-1/2 items-center justify-center rounded-full bg-background/80 text-foreground shadow hover:bg-background"
                        :aria-label="t('ui.next')"
                        @click="step(1)"
                    >
                        <ChevronRight class="rtl-flip size-5" aria-hidden="true" />
                    </button>
                </template>
            </div>

            <div v-if="current" class="flex items-center gap-2 text-xs">
                <span class="min-w-0 flex-1 truncate text-muted-foreground" dir="auto">{{ current.original_name }}</span>
                <a :href="current.url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-primary hover:underline">
                    <Download class="size-3.5" aria-hidden="true" />{{ t('ads.materials.gallery.open') }}
                </a>
            </div>

            <ul v-if="files.length > 1" class="flex gap-2 overflow-x-auto pb-1">
                <li v-for="(f, i) in files" :key="f.id">
                    <button
                        type="button"
                        class="relative block size-16 overflow-hidden rounded-md border-2"
                        :class="i === index ? 'border-primary' : 'border-transparent opacity-70 hover:opacity-100'"
                        :aria-label="t('ads.materials.gallery.show', { n: i + 1 })"
                        :aria-current="i === index"
                        @click="index = i"
                    >
                        <img v-if="f.thumb_url" :src="f.thumb_url" alt="" loading="lazy" class="size-full object-cover" />
                        <span v-else class="flex size-full items-center justify-center bg-muted"
                            ><Play class="size-4 text-muted-foreground" aria-hidden="true"
                        /></span>
                        <Play
                            v-if="isVideo(f.mime) && f.thumb_url"
                            class="absolute inset-0 m-auto size-5 text-white drop-shadow"
                            aria-hidden="true"
                        />
                    </button>
                </li>
            </ul>
        </DialogContent>
    </Dialog>
</template>
