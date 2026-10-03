<script setup lang="ts">
import type { ComposerMode } from '@/composables/inbox/useComposerShortcuts';
import { useI18n } from '@/composables/useI18n';
import { shortcutHint } from '@/composables/useShortcuts';
import { cn } from '@/lib/utils';
import { MessageSquareReply, StickyNote } from 'lucide-vue-next';
import { ref } from 'vue';

/** «رد | ملاحظة»: a two-option radio group (arrow keys move between them, one tab stop). */
defineProps<{ mode: ComposerMode }>();
const emit = defineEmits<{ 'update:mode': [mode: ComposerMode] }>();

const { t, dir } = useI18n();
const buttons = ref<HTMLButtonElement[]>([]);

const options: { value: ComposerMode; label: string; shortcut: string; icon: typeof StickyNote }[] = [
    { value: 'reply', label: 'composer.mode_reply', shortcut: 'inbox.reply', icon: MessageSquareReply },
    { value: 'note', label: 'composer.mode_note', shortcut: 'inbox.note', icon: StickyNote },
];

function hint(id: string): string {
    const key = shortcutHint(id);
    return key ? ` (${key})` : '';
}

function onKeydown(event: KeyboardEvent, index: number): void {
    const forward = dir.value === 'rtl' ? 'ArrowLeft' : 'ArrowRight';
    const back = dir.value === 'rtl' ? 'ArrowRight' : 'ArrowLeft';
    if (![forward, back, 'ArrowDown', 'ArrowUp'].includes(event.key)) return;
    event.preventDefault();
    const step = event.key === forward || event.key === 'ArrowDown' ? 1 : -1;
    const next = (index + step + options.length) % options.length;
    emit('update:mode', options[next].value);
    buttons.value[next]?.focus();
}
</script>

<template>
    <div
        class="inline-flex h-8 shrink-0 items-center rounded-lg bg-muted p-0.5 text-xs font-medium"
        role="radiogroup"
        :aria-label="t('composer.mode_label')"
        data-composer-mode
    >
        <button
            v-for="(option, index) in options"
            :key="option.value"
            ref="buttons"
            type="button"
            role="radio"
            :aria-checked="mode === option.value"
            :tabindex="mode === option.value ? 0 : -1"
            :title="`${t(option.label)}${hint(option.shortcut)}`"
            :class="
                cn(
                    'inline-flex h-7 items-center gap-1 rounded-md px-2.5 outline-none transition-colors focus-visible:ring-2 focus-visible:ring-ring',
                    mode === option.value
                        ? option.value === 'note'
                            ? 'bg-note/25 text-amber-900 shadow-sm dark:bg-note/30 dark:text-amber-100'
                            : 'bg-card text-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground',
                )
            "
            @click="emit('update:mode', option.value)"
            @keydown="onKeydown($event, index)"
        >
            <component :is="option.icon" class="size-3.5" aria-hidden="true" />{{ t(option.label) }}
        </button>
    </div>
</template>
