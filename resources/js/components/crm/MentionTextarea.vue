<script setup lang="ts">
import type { UserRef } from '@/types/crm';
import { computed, nextTick, onMounted, ref, useId, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        users: UserRef[];
        placeholder?: string;
        rows?: number;
        /** Note-double-submit guard (Task 12 fix round 1 carry-over): while a note
         *  request is in flight, Enter must not re-trigger submit and the field
         *  itself is disabled — mirrors the button's own `:disabled`. */
        disabled?: boolean;
        /** Text size — `sm` (Composer's reply-sized note box) or `xs` (CustomerPanel's
         *  compact note box, restoring its original text-xs + dimmed-placeholder look). */
        size?: 'xs' | 'sm';
        /** The signed-in user's id — never shown in the @mention suggestion list. */
        meId?: number;
    }>(),
    { placeholder: undefined, rows: 2, disabled: false, size: 'sm', meId: undefined },
);
const text = defineModel<string>({ required: true });
const mentions = defineModel<number[]>('mentions', { default: () => [] });
const emit = defineEmits<{ submit: [] }>();

const textarea = ref<HTMLTextAreaElement | null>(null);
const menuOpen = ref(false);
const activeIndex = ref(0);
const query = ref('');
const listboxId = useId();

// An "@" immediately preceded by start-of-string or whitespace, with no space
// since, is a live mention token — matched against the text up to the caret so
// a `@` earlier in an already-finished mention never re-opens the menu.
const MENTION = /(^|\s)@([^\s@]*)$/;

const suggestions = computed(() =>
    props.users.filter((u) => u.id !== props.meId && u.name.toLowerCase().includes(query.value.toLowerCase())).slice(0, 6),
);
const activeOptionId = computed(() => (menuOpen.value && suggestions.value.length ? `${listboxId}-${suggestions.value[activeIndex.value].id}` : undefined));
const sizeClass = computed(() => (props.size === 'xs' ? 'text-xs placeholder:opacity-60' : 'text-sm placeholder:text-muted-foreground'));

function caretBefore(): string {
    const el = textarea.value;
    if (!el) return '';
    return text.value.slice(0, el.selectionStart ?? text.value.length);
}

function syncMenu(): void {
    const match = MENTION.exec(caretBefore());
    if (!match) {
        menuOpen.value = false;
        query.value = '';
        return;
    }
    query.value = match[2];
    menuOpen.value = true;
    activeIndex.value = 0;
}

// Deleting (or editing away) the `@Name` text for an existing mention drops its
// id too — the mention list never outlives the text that named it.
function pruneRemovedMentions(): void {
    mentions.value = mentions.value.filter((id) => {
        const user = props.users.find((u) => u.id === id);
        return !!user && text.value.includes(`@${user.name}`);
    });
}

function autosize(): void {
    const el = textarea.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
}

// Covers both native typing (via onInput) and programmatic changes (picking a
// suggestion, the parent clearing the draft once a note/save succeeds).
watch(text, () => nextTick(autosize));

function pick(user: UserRef): void {
    const el = textarea.value;
    const caret = el?.selectionStart ?? text.value.length;
    const before = text.value.slice(0, caret);
    const match = MENTION.exec(before);
    const atIndex = match ? match.index + match[1].length : caret;

    const inserted = `@${user.name} `;
    text.value = text.value.slice(0, atIndex) + inserted + text.value.slice(caret);
    if (!mentions.value.includes(user.id)) mentions.value = [...mentions.value, user.id];

    menuOpen.value = false;
    query.value = '';

    const nextCaret = atIndex + inserted.length;
    nextTick(() => {
        el?.focus();
        el?.setSelectionRange(nextCaret, nextCaret);
    });
}

function onInput(event: Event): void {
    text.value = (event.target as HTMLTextAreaElement).value;
    syncMenu();
    pruneRemovedMentions();
}

function onKeydown(event: KeyboardEvent): void {
    // 229 is the historical IME-composition keyCode some browsers still report
    // for the commit keystroke even after `isComposing` has flipped back to false.
    if (event.isComposing || event.keyCode === 229) return;

    if (menuOpen.value) {
        const count = suggestions.value.length;
        if (event.key === 'Escape') {
            event.preventDefault();
            menuOpen.value = false;
            return;
        }
        if (count && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            event.preventDefault();
            activeIndex.value = (activeIndex.value + (event.key === 'ArrowDown' ? 1 : count - 1)) % count;
            return;
        }
        if (count && (event.key === 'Enter' || event.key === 'Tab')) {
            event.preventDefault();
            pick(suggestions.value[activeIndex.value]);
            return;
        }
        // No matches yet (still typing a name) — let Enter/Tab fall through to
        // the normal newline/focus behaviour rather than pick nothing.
        return;
    }

    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        if (!props.disabled) emit('submit');
    }
}

onMounted(() => nextTick(autosize));

defineExpose({ focus: () => textarea.value?.focus() });
</script>

<template>
    <div class="relative">
        <ul
            v-if="menuOpen && suggestions.length"
            :id="listboxId"
            role="listbox"
            data-state="open"
            class="absolute inset-x-0 bottom-full z-10 mb-1 max-h-48 overflow-y-auto rounded-md border bg-card py-1 shadow-card"
        >
            <li
                v-for="(user, index) in suggestions"
                :id="`${listboxId}-${user.id}`"
                :key="user.id"
                role="option"
                :aria-selected="index === activeIndex"
                class="flex cursor-pointer items-center gap-2 px-2.5 py-1.5 text-xs"
                :class="index === activeIndex ? 'bg-surface-accent text-primary' : 'hover:bg-muted'"
                @mousedown.prevent="pick(user)"
                @mouseenter="activeIndex = index"
            >
                <span class="size-2 shrink-0 rounded-full" :style="{ backgroundColor: user.color || '#64748b' }" aria-hidden="true" />
                <span class="truncate">{{ user.name }}</span>
            </li>
        </ul>

        <textarea
            ref="textarea"
            :value="text"
            :rows="rows"
            dir="auto"
            :disabled="disabled"
            :placeholder="placeholder"
            :aria-label="placeholder"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="menuOpen"
            :aria-controls="menuOpen ? listboxId : undefined"
            :aria-activedescendant="activeOptionId"
            class="w-full resize-none bg-transparent leading-5 outline-none disabled:cursor-not-allowed disabled:opacity-60"
            :class="sizeClass"
            @input="onInput"
            @keydown="onKeydown"
        />
    </div>
</template>
