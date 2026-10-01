<script setup lang="ts">
import type { GalleryItem } from '@/composables/inbox/useThreadGallery';
import { useI18n } from '@/composables/useI18n';
import { ChevronLeft, ChevronRight, Download, LoaderCircle, MessageSquareShare, X } from 'lucide-vue-next';
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue';
import { computed, ref, watch } from 'vue';

/**
 * The thread gallery (Task 6b): one instance per ChatThread, every image and video of the
 * loaded messages in thread order. Arrows follow the reading direction (in RTL ArrowLeft is
 * "next"), Escape closes, a 50 px horizontal swipe pages on touch, focus is trapped by the
 * Radix dialog and handed back to the thumbnail that opened it. Only the current item's
 * full file is requested; the neighbours warm their thumbnails.
 */
const props = withDefaults(
    defineProps<{
        items: GalleryItem[];
        /** The element that opened the gallery; it gets focus back on close. */
        opener?: HTMLElement | null;
        /** Show «روحي للرسالة» (the thread can scroll to the item's message). */
        canJump?: boolean;
    }>(),
    { opener: null, canJump: false },
);
const index = defineModel<number | null>('index', { required: true });
const emit = defineEmits<{ jump: [messageId: number] }>();

const { t, dir } = useI18n();

const open = computed({
    get: () => index.value !== null && props.items.length > 0,
    set: (value: boolean) => {
        if (!value) index.value = null;
    },
});

const current = computed<GalleryItem | null>(() => (index.value !== null ? (props.items[index.value] ?? null) : null));
const many = computed(() => props.items.length > 1);
const counter = computed(() => t('media.counter', { index: (index.value ?? 0) + 1, total: props.items.length }));
const fallbackName = computed(() => (current.value?.type === 'video' ? t('media.preview_video') : t('media.preview_image')));

function step(delta: number): void {
    if (index.value === null || !props.items.length) return;
    index.value = (index.value + delta + props.items.length) % props.items.length;
}
const prev = () => step(-1);
const next = () => step(1);

// Escape on a focused <video> belongs to the gallery, never to the player: claim it in the
// capture phase. (Focus deep inside Chrome's native control bar gets no key events at all.)
function onEscapeCapture(event: KeyboardEvent): void {
    if (event.key !== 'Escape' || !(event.target instanceof HTMLVideoElement)) return;
    event.preventDefault();
    index.value = null;
}

function onKeydown(event: KeyboardEvent): void {
    // A focused <video> keeps its own arrow keys (seek).
    if (event.target instanceof HTMLVideoElement) return;
    const rtl = dir.value === 'rtl';
    if (event.key === 'ArrowLeft') {
        event.preventDefault();
        if (rtl) next();
        else prev();
    } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        if (rtl) prev();
        else next();
    } else if (event.key === 'Home') {
        event.preventDefault();
        index.value = 0;
    } else if (event.key === 'End') {
        event.preventDefault();
        index.value = props.items.length - 1;
    }
}

// Touch swipe: 50 px horizontal, and more horizontal than vertical. In RTL the next item
// sits to the left, so dragging the finger to the right brings it in.
let swipe: { id: number; x: number; y: number } | null = null;
function onPointerDown(event: PointerEvent): void {
    if (event.pointerType === 'mouse' || !many.value) return;
    swipe = { id: event.pointerId, x: event.clientX, y: event.clientY };
}
function onPointerUp(event: PointerEvent): void {
    if (!swipe || swipe.id !== event.pointerId) return;
    const dx = event.clientX - swipe.x;
    const dy = event.clientY - swipe.y;
    swipe = null;
    if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy)) return;
    const forward = dir.value === 'rtl' ? dx > 0 : dx < 0;
    if (forward) next();
    else prev();
}

function onPointerCancel(): void {
    swipe = null;
}

// The full file is loading: a quiet spinner sits behind it until `load`.
const loaded = ref(false);
watch(current, () => (loaded.value = false));

// Warm the neighbours' thumbnails (never their full files).
const warmed = new Set<string>();
watch(
    index,
    (i) => {
        if (i === null || props.items.length < 2) return;
        for (const n of [i - 1, i + 1]) {
            const item = props.items[(n + props.items.length) % props.items.length];
            if (item?.type !== 'image' || !item.thumb || item.thumb === item.url || warmed.has(item.thumb)) continue;
            warmed.add(item.thumb);
            const img = new Image();
            img.decoding = 'async';
            img.src = item.thumb;
        }
    },
    { immediate: true },
);

const content = ref<{ $el: HTMLElement } | null>(null);

// Land focus on the stage itself (arrows work at once) instead of the first header button.
function onOpenAutoFocus(event: Event): void {
    event.preventDefault();
    content.value?.$el?.focus({ preventScroll: true });
}

function onCloseAutoFocus(event: Event): void {
    const el = props.opener;
    if (!el || !el.isConnected) return;
    event.preventDefault();
    el.focus({ preventScroll: true });
}

function jump(): void {
    const item = current.value;
    if (!item) return;
    emit('jump', item.messageId);
}
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay
                class="fixed inset-0 z-50 bg-black/95 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0"
            />
            <DialogContent
                ref="content"
                data-gallery
                class="fixed inset-0 z-50 flex flex-col text-white outline-none data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=open]:zoom-in-[0.98]"
                @keydown="onKeydown"
                @keydown.capture="onEscapeCapture"
                @open-auto-focus="onOpenAutoFocus"
                @close-auto-focus="onCloseAutoFocus"
            >
                <DialogTitle class="sr-only">{{ t('media.gallery') }}</DialogTitle>
                <DialogDescription class="sr-only">{{ t('media.gallery_hint') }}</DialogDescription>

                <header class="flex h-14 shrink-0 items-center gap-1 pe-2 ps-4">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold tabular-nums" aria-live="polite" data-gallery-counter>{{ counter }}</p>
                        <p class="hidden truncate text-2xs text-white/60 sm:block">
                            <bdi>{{ current?.name || fallbackName }}</bdi>
                        </p>
                    </div>

                    <button
                        v-if="canJump && current"
                        type="button"
                        class="inline-flex h-9 items-center gap-1.5 rounded-md px-2.5 text-xs font-medium text-white/90 hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-white"
                        :title="t('media.go_to_message')"
                        data-gallery-jump
                        @click="jump"
                    >
                        <MessageSquareShare class="size-4" aria-hidden="true" />
                        <span class="sr-only sm:not-sr-only">{{ t('media.go_to_message') }}</span>
                    </button>
                    <a
                        v-if="current"
                        :href="current.url"
                        download
                        class="inline-flex size-9 items-center justify-center rounded-md text-white/90 hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-white"
                        :title="t('media.download')"
                        :aria-label="t('media.download')"
                    >
                        <Download class="size-4" aria-hidden="true" />
                    </a>
                    <DialogClose
                        class="inline-flex size-9 items-center justify-center rounded-md text-white/90 hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-white"
                        :aria-label="t('common.close')"
                        :title="t('common.close')"
                        data-gallery-close
                    >
                        <X class="size-5" aria-hidden="true" />
                    </DialogClose>
                </header>

                <div
                    class="relative flex min-h-0 flex-1 touch-pan-y select-none items-center justify-center px-2 pb-4 sm:px-16"
                    @pointerdown="onPointerDown"
                    @pointerup="onPointerUp"
                    @pointercancel="onPointerCancel"
                >
                    <LoaderCircle v-if="current?.type === 'image' && !loaded" class="absolute size-6 animate-spin text-white/50" aria-hidden="true" />

                    <video
                        v-if="current?.type === 'video'"
                        :key="`v-${current.id}`"
                        :src="current.url"
                        controls
                        playsinline
                        preload="metadata"
                        class="max-h-full max-w-full rounded-md bg-black"
                    />
                    <img
                        v-else-if="current"
                        :key="`i-${current.id}`"
                        :src="current.url"
                        :alt="current.name || fallbackName"
                        decoding="async"
                        draggable="false"
                        class="relative max-h-full max-w-full rounded-md object-contain transition-opacity duration-150"
                        :class="loaded ? 'opacity-100' : 'opacity-0'"
                        @load="loaded = true"
                        @error="loaded = true"
                    />

                    <template v-if="many">
                        <button
                            type="button"
                            :aria-label="t('media.previous')"
                            :title="t('media.previous')"
                            class="absolute start-2 top-1/2 flex size-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white backdrop-blur-sm hover:bg-white/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-white sm:start-4"
                            data-gallery-prev
                            @click="prev"
                        >
                            <ChevronLeft class="rtl-flip size-6" aria-hidden="true" />
                        </button>
                        <button
                            type="button"
                            :aria-label="t('media.next')"
                            :title="t('media.next')"
                            class="absolute end-2 top-1/2 flex size-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white backdrop-blur-sm hover:bg-white/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-white sm:end-4"
                            data-gallery-next
                            @click="next"
                        >
                            <ChevronRight class="rtl-flip size-6" aria-hidden="true" />
                        </button>
                    </template>
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
