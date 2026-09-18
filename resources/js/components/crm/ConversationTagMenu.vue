<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { Tag } from '@/types/crm';
import { onClickOutside } from '@vueuse/core';
import { Check } from 'lucide-vue-next';
import { nextTick, onMounted, ref } from 'vue';

const props = defineProps<{ tags: Tag[]; selected: number[]; x: number; y: number }>();
const emit = defineEmits<{ toggle: [tagId: number]; close: [] }>();

const { t } = useI18n();

const root = ref<HTMLElement | null>(null);
const activeIndex = ref(0);
// Clamped to the viewport once the card is measured; starts at the requested
// point (right-click position, or the row's coordinates for a keyboard `t`).
const style = ref({ top: `${props.y}px`, left: `${props.x}px` });

const isSelected = (id: number) => props.selected.includes(id);

function clamp(): void {
    const el = root.value;
    if (!el) return;
    const { offsetWidth: w, offsetHeight: h } = el;
    const left = Math.min(props.x, Math.max(8, window.innerWidth - w - 8));
    const top = Math.min(props.y, Math.max(8, window.innerHeight - h - 8));
    style.value = { top: `${top}px`, left: `${left}px` };
}

function focusActive(): void {
    root.value?.querySelectorAll<HTMLButtonElement>('[data-tag-option]')[activeIndex.value]?.focus();
}

function move(delta: number): void {
    const count = props.tags.length;
    if (!count) return;
    activeIndex.value = (activeIndex.value + delta + count) % count;
    focusActive();
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.preventDefault();
        emit('close');
        return;
    }
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        move(1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        move(-1);
    }
}

onClickOutside(root, () => emit('close'));

onMounted(() => {
    clamp();
    nextTick(focusActive);
});
</script>

<template>
    <div
        ref="root"
        role="menu"
        data-state="open"
        :aria-label="t('thread.tags')"
        class="fixed z-50 w-52 rounded-md border bg-popover p-1 text-popover-foreground shadow-lg"
        :style="style"
        @keydown="onKeydown"
    >
        <p v-if="!tags.length" class="px-2 py-1.5 text-xs text-muted-foreground">{{ t('thread.no_tags') }}</p>
        <button
            v-for="(tag, index) in tags"
            :key="tag.id"
            type="button"
            role="menuitemcheckbox"
            data-tag-option
            :aria-checked="isSelected(tag.id)"
            class="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-start text-xs hover:bg-accent hover:text-accent-foreground focus-visible:bg-accent focus-visible:outline-none"
            @click="emit('toggle', tag.id)"
            @mouseenter="activeIndex = index"
        >
            <span class="flex size-3.5 shrink-0 items-center justify-center">
                <Check v-if="isSelected(tag.id)" class="size-3.5" />
            </span>
            <span class="me-1 size-2 shrink-0 rounded-full" :style="{ backgroundColor: tag.color || '#64748b' }" />
            <span class="truncate">{{ tag.name }}</span>
        </button>
    </div>
</template>
