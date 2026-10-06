<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
import { useI18n } from '@/composables/useI18n';
import { computed } from 'vue';

/** Content-shaped placeholders for partial reloads and deferred props; never a blank area or a lone spinner. */
const props = withDefaults(defineProps<{ variant: 'table' | 'cards' | 'tiles'; count?: number }>(), { count: undefined });

const { t } = useI18n();
const DEFAULTS = { table: 8, cards: 3, tiles: 4 } as const;
const n = computed(() => props.count ?? DEFAULTS[props.variant]);
</script>

<template>
    <div role="status" aria-live="polite" :aria-label="t('ui.loading')" :data-variant="variant">
        <div v-if="variant === 'table'" class="overflow-hidden rounded-lg bg-card shadow-card">
            <div class="flex gap-3 border-b border-border/60 px-3 py-2.5">
                <Skeleton v-for="i in 4" :key="i" class="h-3 flex-1" />
            </div>
            <div v-for="i in n" :key="i" data-skeleton-item class="flex items-center gap-3 border-t border-border/60 px-3 py-2.5 first:border-t-0">
                <Skeleton class="h-3.5 w-1/4" />
                <Skeleton class="h-3.5 flex-1" />
                <Skeleton class="h-3.5 w-16" />
            </div>
        </div>
        <div v-else-if="variant === 'cards'" class="space-y-2">
            <div v-for="i in n" :key="i" data-skeleton-item class="space-y-2 rounded-lg bg-card p-3 shadow-card">
                <Skeleton class="h-4 w-1/2" />
                <Skeleton class="h-3 w-full" />
                <Skeleton class="h-3 w-2/3" />
            </div>
        </div>
        <div v-else class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div v-for="i in n" :key="i" data-skeleton-item class="rounded-lg bg-card px-4 py-3 shadow-card">
                <Skeleton class="h-3 w-1/2" />
                <Skeleton class="mt-2 h-6 w-2/3" />
            </div>
        </div>
    </div>
</template>
