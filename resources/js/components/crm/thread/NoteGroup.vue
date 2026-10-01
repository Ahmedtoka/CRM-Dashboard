<script setup lang="ts">
import RelativeTime from '@/components/crm/RelativeTime.vue';
import NoteLine from '@/components/crm/thread/NoteLine.vue';
import { expandedNotes, toggleExpanded } from '@/components/crm/thread/noteExpansion';
import { useI18n } from '@/composables/useI18n';
import type { Note, UserRef } from '@/types/crm';
import { Minus, Plus, StickyNote } from 'lucide-vue-next';
import { computed, useId } from 'vue';

/** Two or more notes in a row (no message between them) fold into one line. */
const props = withDefaults(defineProps<{ notes: Note[]; mentionable?: UserRef[] }>(), { mentionable: () => [] });

const { t } = useI18n();

const key = computed(() => `g-${props.notes[0]?.id ?? 0}`);
const listId = `note-group-${useId()}`;
const expanded = computed(() => expandedNotes.has(key.value));
const last = computed(() => props.notes[props.notes.length - 1]);
const summary = computed(() => {
    const n = props.notes.length;
    const name = last.value?.user?.name || t('notes.unknown_author');
    const form = n === 2 ? 'group_two' : n <= 10 ? 'group_few' : 'group_many';

    return t(`notes.${form}`, { n, name });
});
</script>

<template>
    <div class="w-full rounded-md bg-note/10 text-xs text-foreground dark:bg-note/[0.12]" data-note-group>
        <button
            type="button"
            class="flex h-8 w-full min-w-0 items-center gap-1.5 rounded-md pe-2 ps-1 text-start outline-none transition-colors hover:bg-note/10 focus-visible:ring-2 focus-visible:ring-ring"
            :aria-expanded="expanded"
            :aria-controls="listId"
            data-note-toggle
            @click="toggleExpanded(key)"
        >
            <span class="sr-only">{{ expanded ? t('notes.group_close') : t('notes.group_open') }}: </span>
            <span class="flex size-6 shrink-0 items-center justify-center rounded text-amber-800 dark:text-amber-200" aria-hidden="true">
                <Minus v-if="expanded" class="size-3.5" />
                <Plus v-else class="size-3.5" />
            </span>
            <StickyNote class="size-3.5 shrink-0 text-amber-700 dark:text-amber-300" aria-hidden="true" />
            <span class="min-w-0 flex-1 truncate font-semibold" dir="auto">{{ summary }}</span>
            <RelativeTime :iso="last?.created_at" mode="stamp" class="shrink-0 text-2xs text-muted-foreground" />
        </button>
        <ul v-show="expanded" :id="listId" class="space-y-1 px-1 pb-1" role="list">
            <li v-for="note in notes" :key="note.id">
                <NoteLine :note="note" :mentionable="mentionable" nested />
            </li>
        </ul>
    </div>
</template>
