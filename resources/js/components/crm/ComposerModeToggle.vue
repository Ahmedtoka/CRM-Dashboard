<script setup lang="ts">
import type { ComposerMode } from '@/composables/inbox/useComposerShortcuts';
import { useI18n } from '@/composables/useI18n';
import { shortcutHint } from '@/composables/useShortcuts';
import { cn } from '@/lib/utils';

defineProps<{ mode: ComposerMode }>();
const emit = defineEmits<{ 'update:mode': [mode: ComposerMode] }>();

const { t } = useI18n();

function hint(id: string): string {
    const key = shortcutHint(id);
    return key ? ` (${key})` : '';
}
</script>

<template>
    <div class="mb-1.5 inline-flex rounded-full bg-elevated p-0.5 text-2xs font-medium" role="tablist" :aria-label="t('composer.mode_reply')">
        <button
            type="button"
            role="tab"
            :aria-selected="mode === 'reply'"
            :title="`${t('composer.mode_reply')}${hint('inbox.reply')}`"
            :class="cn('rounded-full px-2.5 py-1', mode === 'reply' ? 'bg-card shadow-card text-foreground' : 'text-muted-foreground')"
            @click="emit('update:mode', 'reply')"
        >
            {{ t('composer.mode_reply') }}
        </button>
        <button
            type="button"
            role="tab"
            :aria-selected="mode === 'note'"
            :title="`${t('composer.mode_note')}${hint('inbox.note')}`"
            :class="cn('rounded-full px-2.5 py-1', mode === 'note' ? 'bg-card shadow-card text-foreground' : 'text-muted-foreground')"
            @click="emit('update:mode', 'note')"
        >
            {{ t('composer.mode_note') }}
        </button>
    </div>
</template>
