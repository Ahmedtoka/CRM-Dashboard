<script setup lang="ts">
import ChipsInput from '@/components/crm/ChipsInput.vue';
import { useI18n } from '@/composables/useI18n';
import { MAX_OPTION_TITLE, MAX_QUICK_REPLIES, parseAction, withOptionTitle } from '@/lib/flows/flowGraph';
import type { FlowListRow, FlowOption, FlowScriptOption } from '@/types/flows';
import { GripVertical, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    modelValue: FlowOption[];
    kind: 'menu' | 'choice';
    /** step ids a choice option may jump to */
    stepIds: string[];
    flows: FlowListRow[];
    scripts: FlowScriptOption[];
    currentFlowKey: string;
    /** order status steps: each option may be shown only for an open or a finished order */
    allowWhen?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [options: FlowOption[]] }>();

const { t } = useI18n();

const MAX_OPTIONS = MAX_QUICK_REPLIES;
const MAX_TITLE = MAX_OPTION_TITLE;
const ACTION_KINDS = ['flow', 'menu', 'script', 'handover'] as const;
type ActionKind = (typeof ACTION_KINDS)[number];
/** What a choice/status option may do instead of going to a step (FlowEngine jump, 2026-09-19). */
const CHOICE_ACTION_KINDS = ['flow', 'menu', 'handover'] as const;
type ChoiceGo = 'step' | (typeof CHOICE_ACTION_KINDS)[number];
const WHEN_VALUES = ['open', 'finished'] as const;

function update(index: number, patch: Partial<FlowOption>): void {
    const next = props.modelValue.map((o, i) => (i === index ? { ...o, ...patch } : o));
    emit('update:modelValue', next);
}

function setNext(index: number, value: string): void {
    const option = { ...props.modelValue[index] };
    if (value) option.next = value;
    else delete option.next;
    emit(
        'update:modelValue',
        props.modelValue.map((o, i) => (i === index ? option : o)),
    );
}

/** First `option_n` not already used by another option (deletions can free up earlier numbers). */
function nextOptionValue(): string {
    const used = new Set(props.modelValue.map((o) => (o.value ?? '').trim()));
    let n = 1;
    while (used.has(`option_${n}`)) n++;
    return `option_${n}`;
}

function add(): void {
    if (props.modelValue.length >= MAX_OPTIONS) return;
    const option: FlowOption = props.kind === 'menu' ? { title: '', action: '', synonyms: [] } : { title: '', value: nextOptionValue(), synonyms: [] };
    emit('update:modelValue', [...props.modelValue, option]);
}

function remove(index: number): void {
    emit(
        'update:modelValue',
        props.modelValue.filter((_, i) => i !== index),
    );
}

function actionKind(option: FlowOption): ActionKind | '' {
    const { kind } = parseAction(option.action);
    return kind === 'flow' || kind === 'menu' || kind === 'script' || kind === 'handover' ? kind : '';
}

function actionKey(option: FlowOption): string {
    return parseAction(option.action).key;
}

/** Whether the option's current target is one of the choices offered, so an unknown or self target stays visible. */
function targetKnown(option: FlowOption): boolean {
    const key = actionKey(option);
    return actionKind(option) === 'script'
        ? props.scripts.some((s) => s.key === key)
        : props.flows.some((f) => f.key === key && f.key !== props.currentFlowKey);
}

function setActionKind(index: number, kind: string): void {
    update(index, { action: kind === 'handover' ? 'handover' : kind ? `${kind}:` : '' });
}

/** A choice option goes to a step (`next`) unless it carries an `action`. */
function choiceGo(option: FlowOption): ChoiceGo {
    if (option.action === undefined) return 'step';
    const kind = actionKind(option);
    return kind === 'flow' || kind === 'menu' || kind === 'handover' ? kind : 'step';
}

function setChoiceGo(index: number, go: string): void {
    const option: FlowOption = { ...props.modelValue[index] };
    if (go === 'step') {
        delete option.action;
    } else {
        option.action = go === 'handover' ? 'handover' : `${go}:`;
        delete option.next;
    }
    emit(
        'update:modelValue',
        props.modelValue.map((o, i) => (i === index ? option : o)),
    );
}

function setWhen(index: number, value: string): void {
    const option: FlowOption = { ...props.modelValue[index] };
    if (value === 'open' || value === 'finished') option.when = value;
    else delete option.when;
    emit(
        'update:modelValue',
        props.modelValue.map((o, i) => (i === index ? option : o)),
    );
}

function setActionKey(index: number, key: string): void {
    const kind = actionKind(props.modelValue[index]);
    if (kind && kind !== 'handover') update(index, { action: `${kind}:${key}` });
}

/** Client-side hint for a choice value: required and unique within the step (the server validates the same). */
function valueProblem(index: number): string | null {
    const value = (props.modelValue[index]?.value ?? '').trim();
    if (!value) return t('flows.option_value_required');
    return props.modelValue.some((o, i) => i !== index && (o.value ?? '').trim() === value) ? t('flows.option_value_duplicate') : null;
}

// Native drag-and-drop reorder from the grip handle.
const dragFrom = ref<number | null>(null);
const dragOver = ref<number | null>(null);

function onDragStart(event: DragEvent, index: number): void {
    dragFrom.value = index;
    // Firefox only starts a drag when some data is set.
    event.dataTransfer?.setData('text/plain', '');
}

function onDrop(index: number): void {
    const from = dragFrom.value;
    dragFrom.value = null;
    dragOver.value = null;
    if (from === null || from === index) return;
    const next = [...props.modelValue];
    const [moved] = next.splice(from, 1);
    next.splice(index, 0, moved);
    emit('update:modelValue', next);
}

const input = 'h-8 w-full rounded-md border border-input bg-background px-2 text-xs';
</script>

<template>
    <div class="space-y-2">
        <ol class="space-y-2">
            <li
                v-for="(option, index) in modelValue"
                :key="index"
                class="rounded-lg border bg-background/60 p-2 transition-colors"
                :class="dragOver === index && dragFrom !== index ? 'border-primary bg-surface-accent/40' : 'border-border'"
                @dragover.prevent="dragOver = index"
                @dragleave="dragOver = null"
                @drop.prevent="onDrop(index)"
            >
                <div class="flex items-center gap-1.5">
                    <span
                        class="cursor-grab rounded p-0.5 text-muted-foreground hover:bg-muted active:cursor-grabbing"
                        draggable="true"
                        :title="t('flows.drag')"
                        :aria-label="t('flows.drag')"
                        @dragstart="onDragStart($event, index)"
                        @dragend="dragFrom = null"
                    >
                        <GripVertical class="size-4" aria-hidden="true" />
                    </span>
                    <span class="text-2xs font-semibold text-muted-foreground">{{ t('flows.option_n', { n: index + 1 }) }}</span>
                    <button
                        type="button"
                        class="ms-auto rounded p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                        :aria-label="t('flows.remove_option')"
                        :title="t('flows.remove_option')"
                        @click="remove(index)"
                    >
                        <Trash2 class="size-3.5" aria-hidden="true" />
                    </button>
                </div>

                <div class="mt-1.5 grid gap-2">
                    <label class="block">
                        <span class="mb-0.5 flex items-center justify-between text-2xs text-muted-foreground">
                            {{ t('flows.option_title') }}
                            <span class="tabular-nums" :class="(option.title?.length ?? 0) > MAX_TITLE ? 'text-destructive' : ''" dir="ltr">
                                {{ option.title?.length ?? 0 }}/{{ MAX_TITLE }}
                            </span>
                        </span>
                        <input
                            :value="option.title"
                            :maxlength="MAX_TITLE"
                            :class="input"
                            dir="auto"
                            @input="emit('update:modelValue', withOptionTitle(modelValue, index, ($event.target as HTMLInputElement).value))"
                        />
                    </label>

                    <label v-if="kind === 'choice'" class="block">
                        <span class="mb-0.5 block text-2xs text-muted-foreground">{{ t('flows.option_value') }}</span>
                        <input
                            :value="option.value ?? ''"
                            :class="[input, valueProblem(index) ? 'border-destructive' : '']"
                            dir="ltr"
                            :aria-invalid="valueProblem(index) ? 'true' : undefined"
                            @input="update(index, { value: ($event.target as HTMLInputElement).value })"
                        />
                        <span v-if="valueProblem(index)" class="mt-0.5 block text-2xs text-destructive">{{ valueProblem(index) }}</span>
                    </label>

                    <div v-else class="grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="mb-0.5 block text-2xs text-muted-foreground">{{ t('flows.option_action') }}</span>
                            <select
                                :value="actionKind(option)"
                                :class="input"
                                @change="setActionKind(index, ($event.target as HTMLSelectElement).value)"
                            >
                                <option value="">{{ t('flows.action_none') }}</option>
                                <option v-for="k in ACTION_KINDS" :key="k" :value="k">{{ t(`flows.action_${k}`) }}</option>
                            </select>
                        </label>
                        <label v-if="actionKind(option) && actionKind(option) !== 'handover'" class="block">
                            <span class="mb-0.5 block text-2xs text-muted-foreground">{{ t('flows.option_target') }}</span>
                            <select
                                :value="actionKey(option)"
                                :class="input"
                                @change="setActionKey(index, ($event.target as HTMLSelectElement).value)"
                            >
                                <option value="">{{ t('flows.action_none') }}</option>
                                <option v-if="actionKey(option) && !targetKnown(option)" :value="actionKey(option)">{{ actionKey(option) }}</option>
                                <template v-if="actionKind(option) === 'script'">
                                    <option v-for="s in scripts" :key="s.key" :value="s.key">{{ s.title }}</option>
                                </template>
                                <template v-else>
                                    <option v-for="f in flows.filter((f) => f.key !== currentFlowKey)" :key="f.key" :value="f.key">
                                        {{ f.title_ar }}{{ f.is_active ? '' : ` (${t('flows.flow_not_running')})` }}
                                    </option>
                                </template>
                            </select>
                        </label>
                    </div>

                    <label v-if="kind === 'choice'" class="block">
                        <span class="mb-0.5 block text-2xs text-muted-foreground">{{ t('flows.option_go') }}</span>
                        <select :value="choiceGo(option)" :class="input" @change="setChoiceGo(index, ($event.target as HTMLSelectElement).value)">
                            <option value="step">{{ t('flows.option_go_step') }}</option>
                            <option v-for="k in CHOICE_ACTION_KINDS" :key="k" :value="k">{{ t(`flows.action_${k}`) }}</option>
                        </select>
                    </label>

                    <label v-if="kind === 'choice' && (choiceGo(option) === 'flow' || choiceGo(option) === 'menu')" class="block">
                        <span class="mb-0.5 block text-2xs text-muted-foreground">{{ t('flows.option_target') }}</span>
                        <select :value="actionKey(option)" :class="input" @change="setActionKey(index, ($event.target as HTMLSelectElement).value)">
                            <option value="">{{ t('flows.action_none') }}</option>
                            <option v-if="actionKey(option) && !targetKnown(option)" :value="actionKey(option)">{{ actionKey(option) }}</option>
                            <option v-for="f in flows.filter((f) => f.key !== currentFlowKey)" :key="f.key" :value="f.key">
                                {{ f.title_ar }}{{ f.is_active ? '' : ` (${t('flows.flow_not_running')})` }}
                            </option>
                        </select>
                    </label>

                    <label v-if="allowWhen" class="block">
                        <span class="mb-0.5 block text-2xs text-muted-foreground">{{ t('flows.option_when') }}</span>
                        <select :value="option.when ?? ''" :class="input" @change="setWhen(index, ($event.target as HTMLSelectElement).value)">
                            <option value="">{{ t('flows.option_when_always') }}</option>
                            <option v-for="w in WHEN_VALUES" :key="w" :value="w">{{ t(`flows.option_when_${w}`) }}</option>
                        </select>
                    </label>

                    <label v-if="kind === 'choice' && choiceGo(option) === 'step'" class="block">
                        <span class="mb-0.5 block text-2xs text-muted-foreground">{{ t('flows.option_next') }}</span>
                        <select :value="option.next ?? ''" :class="input" @change="setNext(index, ($event.target as HTMLSelectElement).value)">
                            <option value="">{{ t('flows.option_next_default') }}</option>
                            <option v-for="id in stepIds" :key="id" :value="id">{{ id }}</option>
                            <option value="end">{{ t('flows.end') }}</option>
                        </select>
                    </label>

                    <div>
                        <span class="mb-0.5 block text-2xs text-muted-foreground">{{ t('flows.synonyms') }}</span>
                        <ChipsInput
                            :model-value="option.synonyms ?? []"
                            :label="t('flows.synonyms')"
                            :placeholder="t('flows.synonyms_hint')"
                            @update:model-value="update(index, { synonyms: $event })"
                        />
                    </div>
                </div>
            </li>
        </ol>

        <button
            v-if="modelValue.length < MAX_OPTIONS"
            type="button"
            class="inline-flex h-8 w-full items-center justify-center gap-1.5 rounded-md border border-dashed border-input text-xs text-muted-foreground hover:border-primary hover:text-primary"
            @click="add"
        >
            <Plus class="size-3.5" aria-hidden="true" />{{ t('flows.add_option') }}
        </button>
    </div>
</template>
