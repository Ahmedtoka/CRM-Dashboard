<script setup lang="ts">
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useI18n } from '@/composables/useI18n';
import { ListFilter, Search, X } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{ search?: string; searchPlaceholder: string; chips: { key: string; label: string }[]; moreCount?: number; moreLabel?: string }>(),
    { search: '', moreCount: 0, moreLabel: undefined },
);
const emit = defineEmits<{ 'update:search': [string]; remove: [key: string]; clear: [] }>();
// The «فلاتر» popover can be opened from outside too (the inbox's `f` shortcut).
const open = defineModel<boolean>('open', { default: false });
const { t } = useI18n();
const input = ref<HTMLInputElement | null>(null);
defineExpose({ focusSearch: () => input.value?.focus() });

// Debounced search (300 ms), as the inbox list does today.
const term = ref(props.search);
let timer: number | undefined;
watch(term, (v) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => emit('update:search', v.trim()), 300);
});
watch(
    () => props.search,
    (v) => {
        if (term.value.trim() !== v) term.value = v;
    },
);
onBeforeUnmount(() => window.clearTimeout(timer));
</script>

<template>
    <div class="space-y-2">
        <div class="flex items-center gap-2">
            <label class="relative min-w-0 flex-1">
                <span class="sr-only">{{ searchPlaceholder }}</span>
                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <input ref="input" v-model="term" type="search" :placeholder="searchPlaceholder" class="h-9 w-full rounded-full border-0 bg-elevated pe-3 ps-9 text-sm placeholder:text-muted-foreground" />
            </label>
            <div class="hidden items-center gap-2 sm:flex"><slot name="inline" /></div>
            <Popover v-if="$slots.more || $slots.inline" v-model:open="open">
                <PopoverTrigger
                    class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full border border-input bg-card px-3 text-xs font-medium hover:bg-muted"
                    :aria-label="moreLabel ?? t('filters.more')"
                >
                    <ListFilter class="size-4" aria-hidden="true" />
                    <span class="hidden sm:inline">{{ moreLabel ?? t('filters.more') }}</span>
                    <span v-if="moreCount" class="flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-2xs text-primary-foreground tabular-nums">{{ moreCount }}</span>
                </PopoverTrigger>
                <PopoverContent class="w-80 max-w-[calc(100vw-2rem)] space-y-3" :collision-padding="16">
                    <div class="space-y-3 sm:hidden"><slot name="inline" /></div>
                    <slot name="more" />
                </PopoverContent>
            </Popover>
        </div>
        <slot name="tabs" />
        <div v-if="chips.length" class="flex flex-wrap items-center gap-1.5" role="group" :aria-label="t('filters.active')">
            <button
                v-for="chip in chips"
                :key="chip.key"
                type="button"
                class="inline-flex h-6 items-center gap-1 rounded-full bg-surface-accent px-2 text-2xs font-medium text-foreground hover:bg-accent"
                :aria-label="t('filters.remove', { label: chip.label })"
                @click="emit('remove', chip.key)"
            >
                {{ chip.label }}<X class="size-3" aria-hidden="true" />
            </button>
            <button type="button" class="text-2xs font-medium text-primary hover:underline" @click="emit('clear')">{{ t('filters.clear_all') }}</button>
        </div>
    </div>
</template>
