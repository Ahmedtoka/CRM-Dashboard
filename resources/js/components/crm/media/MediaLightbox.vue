<script setup lang="ts">
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { useI18n } from '@/composables/useI18n';
import type { Attachment } from '@/types/crm';
import { ChevronLeft, ChevronRight, Download } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ items: Attachment[] }>();
const index = defineModel<number | null>('index', { required: true });

const { t, dir } = useI18n();

const open = computed({
    get: () => index.value !== null,
    set: (value: boolean) => {
        if (!value) index.value = null;
    },
});

const current = computed(() => (index.value !== null ? props.items[index.value] : null));
const title = computed(() => t('media.lightbox_title', { index: (index.value ?? 0) + 1, total: props.items.length }));

function prev(): void {
    if (index.value === null) return;
    index.value = (index.value - 1 + props.items.length) % props.items.length;
}

function next(): void {
    if (index.value === null) return;
    index.value = (index.value + 1) % props.items.length;
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowLeft') dir.value === 'rtl' ? next() : prev();
    else if (event.key === 'ArrowRight') dir.value === 'rtl' ? prev() : next();
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="flex max-h-[90svh] max-w-3xl flex-col gap-3 bg-background/95 p-4" @keydown="onKeydown">
            <DialogTitle class="sr-only">{{ title }}</DialogTitle>

            <div class="relative flex min-h-0 flex-1 items-center justify-center">
                <button
                    v-if="items.length > 1"
                    type="button"
                    :aria-label="t('media.previous')"
                    class="absolute start-1 top-1/2 flex size-8 -translate-y-1/2 items-center justify-center rounded-full bg-black/50 text-white hover:bg-black/70"
                    @click="prev"
                >
                    <ChevronLeft class="size-5 rtl-flip" aria-hidden="true" />
                </button>

                <video
                    v-if="current?.type === 'video'"
                    controls
                    :src="current.url ?? undefined"
                    :poster="current.thumb_url ?? undefined"
                    class="max-h-[70svh] max-w-full rounded-md"
                />
                <img v-else-if="current" :src="current.url ?? current.thumb_url ?? ''" :alt="title" class="max-h-[70svh] max-w-full rounded-md object-contain" />

                <button
                    v-if="items.length > 1"
                    type="button"
                    :aria-label="t('media.next')"
                    class="absolute end-1 top-1/2 flex size-8 -translate-y-1/2 items-center justify-center rounded-full bg-black/50 text-white hover:bg-black/70"
                    @click="next"
                >
                    <ChevronRight class="size-5 rtl-flip" aria-hidden="true" />
                </button>
            </div>

            <div class="flex items-center justify-between text-xs text-muted-foreground">
                <span class="tabular-nums">{{ title }}</span>
                <a
                    v-if="current?.url"
                    :href="current.url"
                    download
                    class="inline-flex items-center gap-1 rounded px-2 py-1 font-medium hover:bg-muted"
                >
                    <Download class="size-3.5" aria-hidden="true" />{{ t('media.download') }}
                </a>
            </div>
        </DialogContent>
    </Dialog>
</template>
