<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { MAX_OPTION_TITLE } from '@/lib/flows/flowGraph';
import { INLINE_EDIT } from '@/lib/flows/inlineEdit';
import { stepColor, stepIcon } from '@/lib/flows/stepVisuals';
import type { StepNodeData } from '@/types/flows';
import { Handle, Position, type NodeProps } from '@vue-flow/core';
import { CircleAlert, Flag, GitBranch } from 'lucide-vue-next';
import { computed, inject, nextTick, onBeforeUnmount, ref } from 'vue';

const props = defineProps<NodeProps<StepNodeData>>();

const { t, locale } = useI18n();

const info = computed(() => props.data.info);
const step = computed(() => props.data.step);
const color = computed(() => stepColor(info.value?.color));
const icon = computed(() => stepIcon(info.value?.icon));

const typeLabel = computed(() => {
    if (locale.value === 'ar' && info.value?.label_ar) return info.value.label_ar;
    const key = `flows.types.${step.value.type}`;
    const text = t(key);
    return text === key ? step.value.type : text;
});

/** What the body shows when a step has no message text of its own. */
const bodyText = computed(() => {
    if (step.value.text) return step.value.text;
    if (step.value.type === 'script' || step.value.type === 'record_case') return step.value.script ? `📄 ${step.value.script}` : '';
    if (step.value.type === 'status') return t('flows.status_hint');
    if (step.value.type === 'end') return t('flows.end_hint');
    return '';
});

const caseType = computed(() => (step.value.type === 'record_case' && step.value.case_type ? t(`cases.types.${step.value.case_type}`) : null));
const showNext = computed(() => !!info.value?.has_next || step.value.next !== undefined);
const errorCount = computed(() => props.data.errors.length);

// ---- inline editing (design 2026-09-18 §3): double-click the text, an option title or the id chip ----
const edit = inject(INLINE_EDIT, null);

type Editing = { kind: 'text' } | { kind: 'option'; index: number } | { kind: 'id' };
const editing = ref<Editing | null>(null);
const value = ref('');
const editorEl = ref<HTMLTextAreaElement | HTMLInputElement | null>(null);
/** A function ref: the option editor sits inside a v-for, where a string ref would collect an array. */
const setEditor = (el: unknown): void => {
    editorEl.value = el instanceof HTMLTextAreaElement || el instanceof HTMLInputElement ? el : null;
};

const canEditText = computed(() => !!edit && !!info.value?.fields.includes('text'));
const renameError = computed(() => (editing.value?.kind === 'id' && edit ? edit.renameProblem(props.data.stepId, value.value.trim()) : null));
const titleLength = computed(() => [...value.value].length);

function open(next: Editing, initial: string): void {
    if (!edit) return;
    editing.value = next;
    value.value = initial;
    edit.setEditing(true);
    nextTick(() => {
        const el = editorEl.value;
        if (!el) return;
        el.focus();
        el.select();
        grow();
    });
}

function startText(): void {
    if (canEditText.value) open({ kind: 'text' }, step.value.text ?? '');
}

function startOption(index: number): void {
    const option = step.value.options?.[index];
    if (option) open({ kind: 'option', index }, option.title ?? '');
}

function startId(): void {
    open({ kind: 'id' }, props.data.stepId);
}

function onInput(): void {
    const current = editing.value;
    grow();
    if (!edit || !current) return;
    if (current.kind === 'text') edit.draft({ stepId: props.data.stepId, text: value.value });
    else if (current.kind === 'option') edit.draft({ stepId: props.data.stepId, option: { index: current.index, title: value.value } });
}

function close(): void {
    editing.value = null;
    edit?.draft(null);
    edit?.setEditing(false);
}

/** Enter (or leaving the field) saves; an invalid id stays open with its message, and leaving it keeps the old id. */
function commit(fromBlur = false): void {
    const current = editing.value;
    if (!edit || !current) return;
    if (current.kind === 'id') {
        const to = value.value.trim();
        if (edit.renameProblem(props.data.stepId, to)) {
            if (fromBlur) close();
            return;
        }
        close();
        edit.rename(props.data.stepId, to);
        return;
    }
    close();
    if (current.kind === 'text') edit.commitText(props.data.stepId, value.value);
    else edit.commitOptionTitle(props.data.stepId, current.index, [...value.value].slice(0, MAX_OPTION_TITLE).join(''));
}

function onKeydown(event: KeyboardEvent): void {
    event.stopPropagation();
    if (event.isComposing) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        close();
    } else if (event.key === 'Enter' && !(editing.value?.kind === 'text' && event.shiftKey)) {
        event.preventDefault();
        commit();
    }
}

function grow(): void {
    const el = editorEl.value;
    if (el instanceof HTMLTextAreaElement) {
        el.style.height = 'auto';
        el.style.height = `${el.scrollHeight}px`;
    }
}

onBeforeUnmount(() => {
    if (editing.value) close();
});
</script>

<template>
    <div
        class="flow-step relative w-[260px] rounded-xl border bg-card text-card-foreground shadow-card transition-shadow"
        :class="[
            errorCount ? 'border-destructive ring-2 ring-destructive/25' : data.isStart ? 'border-emerald-600/60' : 'border-border',
            selected ? 'ring-2 ring-primary/60' : '',
            data.highlighted ? 'ring-4 ring-amber-400/70' : '',
        ]"
        dir="rtl"
    >
        <Handle id="in" type="target" :position="Position.Top" class="flow-handle flow-handle--in" />

        <!-- Start marker: a pill sitting on the top edge, and the node's border turns green. -->
        <span
            v-if="data.isStart"
            class="absolute -top-3 start-4 z-10 inline-flex items-center gap-1 rounded-full bg-emerald-600 px-2.5 py-0.5 text-2xs font-semibold text-white shadow-card"
        >
            <Flag class="size-3" aria-hidden="true" />{{ t('flows.start') }}
        </span>

        <div class="h-1.5 rounded-t-xl" :class="color.strip" aria-hidden="true" />

        <header class="flex items-center gap-2 px-3 pb-1.5 pt-2">
            <span class="flex size-7 shrink-0 items-center justify-center rounded-lg" :class="color.tile">
                <component :is="icon" class="size-4" aria-hidden="true" />
            </span>
            <span class="min-w-0 flex-1">
                <span class="block truncate text-xs font-semibold">{{ typeLabel }}</span>
                <template v-if="editing?.kind === 'id'">
                    <input
                        :ref="setEditor"
                        v-model="value"
                        class="nodrag nopan nowheel block h-5 w-full rounded border bg-background px-1 text-2xs"
                        :class="renameError ? 'border-destructive' : 'border-primary'"
                        dir="ltr"
                        maxlength="40"
                        :aria-label="t('flows.rename_step')"
                        :aria-invalid="renameError ? 'true' : undefined"
                        @keydown="onKeydown"
                        @blur="commit(true)"
                    />
                    <span v-if="renameError" class="mt-0.5 block text-2xs leading-4 text-destructive" role="alert">{{ renameError }}</span>
                </template>
                <span
                    v-else
                    class="block cursor-text truncate text-2xs text-muted-foreground"
                    dir="ltr"
                    :title="edit ? t('flows.inline.dblclick_rename') : undefined"
                    @dblclick.stop="startId"
                    >{{ data.stepId }}</span
                >
            </span>
            <span
                v-if="errorCount"
                class="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-destructive px-1.5 py-0.5 text-2xs font-semibold text-destructive-foreground"
                :title="t('flows.errors_count', { n: errorCount })"
            >
                <CircleAlert class="size-3" aria-hidden="true" />{{ errorCount }}
            </span>
        </header>

        <div class="px-3 pb-2">
            <template v-if="editing?.kind === 'text'">
                <textarea
                    :ref="setEditor"
                    v-model="value"
                    rows="2"
                    dir="auto"
                    class="nodrag nopan nowheel block max-h-60 w-full resize-none rounded-md border border-primary bg-background px-2 py-1 text-xs leading-5"
                    :aria-label="t('flows.text')"
                    @input="onInput"
                    @keydown="onKeydown"
                    @blur="commit(true)"
                />
                <span class="mt-0.5 block text-2xs text-muted-foreground">{{ t('flows.inline.text_keys') }}</span>
            </template>
            <p
                v-else-if="bodyText"
                class="line-clamp-3 whitespace-pre-line text-xs leading-5 text-foreground/80"
                :class="canEditText ? 'cursor-text' : ''"
                dir="auto"
                :title="canEditText ? t('flows.inline.dblclick_edit') : undefined"
                @dblclick.stop="startText"
            >
                {{ bodyText }}
            </p>
            <p
                v-else
                class="text-xs italic text-muted-foreground"
                :class="canEditText ? 'cursor-text' : ''"
                :title="canEditText ? t('flows.inline.dblclick_edit') : undefined"
                @dblclick.stop="startText"
            >
                {{ t('flows.empty_text') }}
            </p>
            <p v-if="caseType" class="mt-1 inline-flex rounded bg-rose-500/10 px-1.5 py-0.5 text-2xs text-rose-700 dark:text-rose-300">
                {{ caseType }}
            </p>
        </div>

        <ul v-if="step.options?.length" class="space-y-1 border-t border-border/60 px-2 py-1.5">
            <li
                v-for="(option, index) in step.options"
                :key="index"
                class="relative flex h-6 items-center gap-1.5 rounded-md bg-muted/70 px-2 text-2xs"
            >
                <template v-if="editing?.kind === 'option' && editing.index === index">
                    <input
                        :ref="setEditor"
                        v-model="value"
                        class="nodrag nopan nowheel h-5 min-w-0 flex-1 rounded border border-primary bg-background px-1 text-2xs"
                        dir="auto"
                        :maxlength="MAX_OPTION_TITLE"
                        :aria-label="t('flows.option_title')"
                        @input="onInput"
                        @keydown="onKeydown"
                        @blur="commit(true)"
                    />
                    <span
                        class="shrink-0 tabular-nums"
                        :class="titleLength >= MAX_OPTION_TITLE ? 'font-semibold text-destructive' : 'text-muted-foreground'"
                        dir="ltr"
                        >{{ titleLength }}/{{ MAX_OPTION_TITLE }}</span
                    >
                </template>
                <span
                    v-else
                    class="min-w-0 flex-1 cursor-text truncate font-medium"
                    dir="auto"
                    :title="edit ? t('flows.inline.dblclick_edit') : undefined"
                    @dblclick.stop="startOption(index)"
                    >{{ option.title || '—' }}</span
                >
                <span
                    v-if="option.value && !(editing?.kind === 'option' && editing.index === index)"
                    class="max-w-[70px] shrink-0 truncate text-muted-foreground"
                    dir="ltr"
                    >{{ option.value }}</span
                >
                <Handle :id="`option:${index}`" type="source" :position="Position.Left" class="flow-handle flow-handle--option" />
            </li>
        </ul>

        <ul v-if="step.branches?.length" class="space-y-1 border-t border-dashed border-border px-2 py-1.5">
            <li
                v-for="(branch, index) in step.branches"
                :key="index"
                class="relative flex h-6 items-center gap-1.5 rounded-md border border-dashed border-amber-500/50 bg-amber-500/5 px-2 text-2xs"
            >
                <GitBranch class="size-3 shrink-0 text-amber-600" aria-hidden="true" />
                <span class="min-w-0 flex-1 truncate" dir="auto">{{ data.branchLabels[index] ?? branch.field }}</span>
                <Handle :id="`branch:${index}`" type="source" :position="Position.Left" class="flow-handle flow-handle--branch" />
            </li>
        </ul>

        <Handle v-if="showNext" id="next" type="source" :position="Position.Bottom" class="flow-handle flow-handle--next" />
    </div>
</template>
