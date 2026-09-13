<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { QuickReply } from '@/types/crm';
import { nextTick, ref, watch } from 'vue';

const props = defineProps<{ items: QuickReply[]; activeIndex: number }>();
const emit = defineEmits<{ pick: [reply: QuickReply]; hover: [index: number] }>();

const { t } = useI18n();
const root = ref<HTMLElement | null>(null);

watch(
    () => props.activeIndex,
    (index) => nextTick(() => root.value?.querySelector<HTMLElement>(`[data-index="${index}"]`)?.scrollIntoView({ block: 'nearest' })),
);
</script>

<template>
    <div
        ref="root"
        id="quick-reply-menu"
        role="listbox"
        :aria-label="t('composer.quick_replies')"
        class="scrollbar-thin absolute inset-x-3 bottom-full z-20 mb-1 max-h-64 overflow-y-auto rounded-lg border bg-popover p-1 shadow-lg"
    >
        <p class="px-2 py-1 text-2xs font-medium text-muted-foreground">{{ t('composer.quick_replies') }}</p>
        <p v-if="!items.length" class="px-2 py-2 text-xs text-muted-foreground">{{ t('composer.no_quick_replies') }}</p>
        <button
            v-for="(item, index) in items"
            :key="item.id"
            :data-index="index"
            type="button"
            role="option"
            tabindex="-1"
            :aria-selected="index === activeIndex"
            class="flex w-full flex-col items-start gap-0.5 rounded-md px-2 py-1.5 text-start"
            :class="index === activeIndex ? 'bg-accent text-accent-foreground' : 'hover:bg-muted'"
            @mousedown.prevent="emit('pick', item)"
            @mousemove="emit('hover', index)"
        >
            <span class="flex items-center gap-2 text-xs">
                <kbd class="rounded bg-muted px-1 font-mono text-2xs text-muted-foreground" dir="ltr">/{{ item.shortcut }}</kbd>
                <span class="font-medium">{{ item.title }}</span>
            </span>
            <span class="line-clamp-1 text-2xs text-muted-foreground" dir="auto">{{ item.body }}</span>
        </button>
    </div>
</template>
