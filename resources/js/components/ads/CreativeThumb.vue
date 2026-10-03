<script setup lang="ts">
/** Creative thumbnail with the Arena pills: status bottom-start, created date bottom-end; icon when the image is missing or expired. */
import { useI18n } from '@/composables/useI18n';
import { formatIsoDayShort, isAdActive, safeUrl } from '@/lib/ads';
import type { CreativeRow } from '@/types/ads';
import { ImageOff, Play } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        ad: Pick<CreativeRow, 'name' | 'thumbnail_url' | 'image_url' | 'type' | 'effective_status' | 'created_time'>;
        /** Fixed width in px; `fill` stretches to the parent (card grids). */
        size?: number | 'fill';
        showPills?: boolean;
        /** No corner rounding (flush in a card). */
        square?: boolean;
    }>(),
    { size: 120, showPills: true, square: false },
);

const { t, locale } = useI18n();
const failed = ref(false);
const src = computed(() => safeUrl(props.ad.thumbnail_url) ?? safeUrl(props.ad.image_url));
watch(src, () => (failed.value = false));

const active = computed(() => isAdActive(props.ad));
const date = computed(() => formatIsoDayShort(props.ad.created_time, locale.value));
const box = computed(() => (props.size === 'fill' ? undefined : { width: `${props.size}px`, height: `${props.size}px` }));
</script>

<template>
    <div
        class="relative shrink-0 overflow-hidden bg-muted"
        :class="[size === 'fill' ? 'aspect-square w-full' : '', square ? '' : 'rounded-md']"
        :style="box"
    >
        <img
            v-if="src && !failed"
            :src="src"
            :alt="ad.name"
            loading="lazy"
            referrerpolicy="no-referrer"
            class="size-full object-cover"
            @error="failed = true"
        />
        <div v-else class="flex size-full items-center justify-center text-muted-foreground">
            <ImageOff class="size-6" aria-hidden="true" />
            <span class="sr-only">{{ t('ads.creative.no_image') }}</span>
        </div>
        <span
            v-if="ad.type === 'video'"
            class="absolute start-1.5 top-1.5 flex size-6 items-center justify-center rounded-full bg-black/60 text-white"
        >
            <Play class="size-3" aria-hidden="true" />
            <span class="sr-only">{{ t('ads.type.video') }}</span>
        </span>
        <slot />
        <template v-if="showPills">
            <span
                class="absolute bottom-1.5 start-1.5 inline-flex h-5 items-center gap-1 rounded-full px-1.5 text-2xs font-semibold text-white"
                :class="active ? 'bg-success/90' : 'bg-black/60'"
            >
                <span class="size-1.5 rounded-full" :class="active ? 'bg-white' : 'bg-white/60'" aria-hidden="true" />
                {{ active ? t('ads.status.active') : t('ads.status.inactive') }}
            </span>
            <span v-if="date" class="absolute bottom-1.5 end-1.5 inline-flex h-5 items-center rounded-full bg-black/60 px-1.5 text-2xs text-white">{{
                date
            }}</span>
        </template>
    </div>
</template>
