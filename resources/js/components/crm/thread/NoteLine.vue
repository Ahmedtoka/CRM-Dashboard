<script setup lang="ts">
import RelativeTime from '@/components/crm/RelativeTime.vue';
import { expandedNotes, toggleExpanded } from '@/components/crm/thread/noteExpansion';
import { useI18n } from '@/composables/useI18n';
import { noteFirstLine, noteParts } from '@/lib/noteBody';
import type { Note, UserRef } from '@/types/crm';
import { Minus, Plus, StickyNote } from 'lucide-vue-next';
import { computed, useId } from 'vue';

const props = withDefaults(
    defineProps<{
        note: Note;
        mentionable?: UserRef[];
        /** Inside an open NoteGroup: a quieter surface, so the tints do not stack into a dark block. */
        nested?: boolean;
    }>(),
    { mentionable: () => [], nested: false },
);

const { t } = useI18n();

const key = computed(() => `n-${props.note.id}`);
/** Per instance: the same note can show in the thread and in the details panel at once. */
const bodyId = `note-body-${useId()}`;
const expanded = computed(() => expandedNotes.has(key.value));
const author = computed(() => props.note.user?.name || t('notes.unknown_author'));
const firstLine = computed(() => noteFirstLine(props.note.body));
const parts = computed(() => (expanded.value ? noteParts(props.note, props.mentionable) : []));
</script>

<template>
    <div
        class="w-full rounded-md text-xs text-foreground"
        :class="nested ? 'bg-background/70' : 'bg-note/10 dark:bg-note/[0.12]'"
        :data-note-id="note.id"
    >
        <button
            type="button"
            class="flex h-8 w-full min-w-0 items-center gap-1.5 rounded-md pe-2 ps-1 text-start outline-none transition-colors hover:bg-note/10 focus-visible:ring-2 focus-visible:ring-ring"
            :aria-expanded="expanded"
            :aria-controls="bodyId"
            data-note-toggle
            @click="toggleExpanded(key)"
        >
            <span class="sr-only">{{ expanded ? t('notes.close') : t('notes.open') }}: </span>
            <span class="flex size-6 shrink-0 items-center justify-center rounded text-amber-800 dark:text-amber-200" aria-hidden="true">
                <Minus v-if="expanded" class="size-3.5" />
                <Plus v-else class="size-3.5" />
            </span>
            <StickyNote class="size-3.5 shrink-0 text-amber-700 dark:text-amber-300" aria-hidden="true" />
            <span class="max-w-[9rem] shrink-0 truncate font-semibold" dir="auto">{{ author }}</span>
            <span class="min-w-0 flex-1 truncate text-muted-foreground" dir="auto">{{ expanded ? t('thread.note') : firstLine }}</span>
            <RelativeTime :iso="note.created_at" mode="stamp" class="shrink-0 text-2xs text-muted-foreground" />
        </button>
        <p v-show="expanded" :id="bodyId" class="whitespace-pre-wrap break-words px-3 pb-2.5 pt-0.5 text-sm leading-relaxed" dir="auto">
            <template v-for="(part, index) in parts" :key="index">
                <span v-if="part.mention" class="font-semibold text-primary">{{ part.text }}</span>
                <template v-else>{{ part.text }}</template>
            </template>
        </p>
    </div>
</template>
