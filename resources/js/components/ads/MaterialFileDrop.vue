<script setup lang="ts">
/**
 * Drag & drop (or pick) several images / videos for a material, with local previews.
 * Type and size are checked here first (the server checks the real bytes again); preview object URLs are
 * revoked when a file is removed, when the parent clears the list and on unmount.
 */
import { useI18n } from '@/composables/useI18n';
import { ACCEPT_FILES, fileKind, isVideo } from '@/lib/adsMaterials';
import { formatBytes } from '@/lib/format';
import type { MaterialFile } from '@/types/ads';
import { Film, ImagePlus, RotateCcw, Trash2, UploadCloud } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: File[];
        existing?: MaterialFile[];
        removed?: number[];
        limits?: { image_mb: number; video_mb: number; post_mb?: number | null };
        /** Upload percentage while the form is sending, else null. */
        progress?: number | null;
        disabled?: boolean;
        maxFiles?: number;
    }>(),
    { existing: () => [], removed: () => [], limits: () => ({ image_mb: 20, video_mb: 500 }), progress: null, disabled: false, maxFiles: 20 },
);

const emit = defineEmits<{ 'update:modelValue': [files: File[]]; 'update:removed': [ids: number[]] }>();

const { t, locale } = useI18n();

const dragging = ref(false);
/** dragenter / dragleave also fire for every child the pointer crosses: count them so the highlight does not flicker. */
let dragDepth = 0;
function onDragEnter(): void {
    dragDepth++;
    dragging.value = true;
}
function onDragLeave(): void {
    dragDepth = Math.max(0, dragDepth - 1);
    if (dragDepth === 0) dragging.value = false;
}
const errors = ref<string[]>([]);
const input = ref<HTMLInputElement | null>(null);

/* ---- previews ---- */
const previews = new Map<File, string>();
const previewOf = (file: File): string => {
    let url = previews.get(file);
    if (!url) {
        url = URL.createObjectURL(file);
        previews.set(file, url);
    }

    return url;
};
function revoke(file: File): void {
    const url = previews.get(file);
    if (url) URL.revokeObjectURL(url);
    previews.delete(file);
}
watch(
    () => props.modelValue,
    (files) => {
        for (const file of [...previews.keys()]) if (!files.includes(file)) revoke(file);
    },
);
onBeforeUnmount(() => {
    for (const file of [...previews.keys()]) revoke(file);
});

/* ---- adding ---- */
const keptExisting = computed(() => props.existing.filter((f) => !props.removed.includes(f.id)).length);

function add(list: FileList | File[] | null | undefined): void {
    if (!list || props.disabled) return;
    const next = [...props.modelValue];
    const problems: string[] = [];
    for (const file of Array.from(list)) {
        const kind = fileKind(file.type);
        if (kind === null) {
            problems.push(t('ads.materials.files.bad_type', { name: file.name }));
            continue;
        }
        const mb = kind === 'video' ? props.limits.video_mb : props.limits.image_mb;
        if (file.size > mb * 1024 * 1024) {
            problems.push(t('ads.materials.files.too_big', { name: file.name, mb }));
            continue;
        }
        const postMb = props.limits.post_mb;
        if (postMb && [...next, file].reduce((sum, f) => sum + f.size, 0) > postMb * 1024 * 1024) {
            problems.push(t('ads.materials.files.too_big_total', { name: file.name, mb: postMb }));
            continue;
        }
        if (next.length + keptExisting.value >= props.maxFiles) {
            problems.push(t('ads.materials.files.too_many', { n: props.maxFiles }));
            break;
        }
        next.push(file);
    }
    errors.value = problems;
    emit('update:modelValue', next);
}

function onDrop(e: DragEvent): void {
    dragDepth = 0;
    dragging.value = false;
    add(e.dataTransfer?.files);
}

function onPick(e: Event): void {
    const el = e.target as HTMLInputElement;
    add(el.files);
    el.value = '';
}

function removeNew(file: File): void {
    revoke(file);
    emit(
        'update:modelValue',
        props.modelValue.filter((f) => f !== file),
    );
}

function toggleExisting(id: number): void {
    emit('update:removed', props.removed.includes(id) ? props.removed.filter((x) => x !== id) : [...props.removed, id]);
}

const size = (bytes: number | null) => formatBytes(bytes, locale.value);
</script>

<template>
    <div class="space-y-3">
        <div
            class="flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed px-4 py-6 text-center transition-colors"
            :class="dragging ? 'border-primary bg-primary/5' : 'border-border bg-muted/30'"
            @dragenter.prevent="onDragEnter"
            @dragover.prevent
            @dragleave.prevent="onDragLeave"
            @drop.prevent="onDrop"
        >
            <UploadCloud class="size-7 text-muted-foreground" aria-hidden="true" />
            <p class="text-sm font-medium">{{ t('ads.materials.files.drop') }}</p>
            <p class="text-2xs text-muted-foreground">{{ t('ads.materials.files.hint', { image: limits.image_mb, video: limits.video_mb }) }}</p>
            <button
                type="button"
                class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border bg-background px-3 text-xs font-medium hover:bg-muted disabled:opacity-50"
                :disabled="disabled"
                @click="input?.click()"
            >
                <ImagePlus class="size-3.5" aria-hidden="true" />{{ t('ads.materials.files.pick') }}
            </button>
            <input
                ref="input"
                type="file"
                multiple
                :accept="ACCEPT_FILES"
                class="sr-only"
                :aria-label="t('ads.materials.files.pick')"
                @change="onPick"
            />
        </div>

        <ul v-if="errors.length" role="alert" class="space-y-1 rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">
            <li v-for="(e, i) in errors" :key="i">{{ e }}</li>
        </ul>

        <div v-if="progress !== null" class="space-y-1" role="status">
            <div class="flex justify-between text-2xs text-muted-foreground">
                <span>{{ t('ads.materials.files.uploading') }}</span>
                <span class="tabular-nums">{{ progress }}%</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-muted">
                <div class="h-full rounded-full bg-primary transition-[width]" :style="{ width: `${progress}%` }" />
            </div>
        </div>

        <ul v-if="existing.length || modelValue.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            <li
                v-for="f in existing"
                :key="`e-${f.id}`"
                class="relative overflow-hidden rounded-lg border border-border bg-card"
                :class="{ 'opacity-40': removed.includes(f.id) }"
            >
                <video
                    v-if="isVideo(f.mime)"
                    :src="f.url"
                    :poster="f.thumb_url ?? undefined"
                    controls
                    preload="metadata"
                    class="aspect-square w-full bg-black object-contain"
                />
                <img v-else :src="f.thumb_url ?? f.url" :alt="f.original_name ?? ''" loading="lazy" class="aspect-square w-full object-cover" />
                <div class="flex items-center gap-1 px-2 py-1.5 text-2xs">
                    <span class="min-w-0 flex-1 truncate" dir="auto" :title="f.original_name ?? ''">{{ f.original_name ?? '—' }}</span>
                    <button
                        type="button"
                        class="inline-flex size-6 items-center justify-center rounded text-muted-foreground hover:bg-muted hover:text-destructive"
                        :aria-label="removed.includes(f.id) ? t('ads.materials.files.keep') : t('ads.materials.files.remove')"
                        :title="removed.includes(f.id) ? t('ads.materials.files.keep') : t('ads.materials.files.remove')"
                        :disabled="disabled"
                        @click="toggleExisting(f.id)"
                    >
                        <RotateCcw v-if="removed.includes(f.id)" class="size-3.5" aria-hidden="true" />
                        <Trash2 v-else class="size-3.5" aria-hidden="true" />
                    </button>
                </div>
            </li>
            <li v-for="(f, i) in modelValue" :key="`n-${i}-${f.name}`" class="relative overflow-hidden rounded-lg border border-primary/40 bg-card">
                <video v-if="isVideo(f.type)" :src="previewOf(f)" controls preload="metadata" class="aspect-square w-full bg-black object-contain" />
                <img v-else :src="previewOf(f)" :alt="f.name" class="aspect-square w-full object-cover" />
                <span
                    class="absolute start-1.5 top-1.5 inline-flex items-center gap-1 rounded-full bg-primary px-2 py-0.5 text-2xs font-medium text-primary-foreground"
                >
                    <Film v-if="isVideo(f.type)" class="size-3" aria-hidden="true" />{{ t('ads.materials.files.new') }}
                </span>
                <div class="flex items-center gap-1 px-2 py-1.5 text-2xs">
                    <span class="min-w-0 flex-1 truncate" dir="auto" :title="f.name">{{ f.name }}</span>
                    <span class="shrink-0 tabular-nums text-muted-foreground">{{ size(f.size) }}</span>
                    <button
                        type="button"
                        class="inline-flex size-6 items-center justify-center rounded text-muted-foreground hover:bg-muted hover:text-destructive"
                        :aria-label="t('ads.materials.files.remove')"
                        :title="t('ads.materials.files.remove')"
                        :disabled="disabled"
                        @click="removeNew(f)"
                    >
                        <Trash2 class="size-3.5" aria-hidden="true" />
                    </button>
                </div>
            </li>
        </ul>
    </div>
</template>
