<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { STEP_ID_PROBLEM_KEYS, fieldsOf, renameStep, setStepText, stepIdProblem, updateStep } from '@/lib/flows/flowGraph';
import { stepColor, stepIcon, stepTypeLabel } from '@/lib/flows/stepVisuals';
import type { FlowBranch, FlowDefinition, FlowListRow, FlowOption, FlowScriptOption, FlowStep, StepTypeCatalog } from '@/types/flows';
import { CircleAlert, Flag, Trash2, TriangleAlert } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import BranchesEditor from './BranchesEditor.vue';
import OptionsEditor from './OptionsEditor.vue';

const props = defineProps<{
    def: FlowDefinition;
    stepId: string;
    catalog: StepTypeCatalog;
    flows: FlowListRow[];
    scripts: FlowScriptOption[];
    flowKey: string;
    /** already-translated validation errors about this step */
    errors: string[];
    /** already-translated warnings about this step */
    warnings: string[];
}>();

const emit = defineEmits<{
    change: [def: FlowDefinition, selectId?: string];
    delete: [];
}>();

const { t, locale } = useI18n();

const CASE_TYPES = ['return', 'exchange', 'return_exchange', 'complaint', 'cancel_edit', 'delivery_followup'];

/** Flow placeholders FlowPrompter::renderText fills in a step's text (2026-09-19). */
const PLACEHOLDERS = [
    'customer_first_name',
    'order_number',
    'exchange_product_title',
    'case_id',
    'time_greeting',
    'order_date',
    'order_items',
    'order_status',
    'order_eta',
    'order_tracking',
];

const step = computed<FlowStep>(() => props.def.steps[props.stepId]);
const info = computed(() => props.catalog[step.value?.type] ?? null);
const isStart = computed(() => props.def.start === props.stepId);
const color = computed(() => stepColor(info.value?.color));
const icon = computed(() => stepIcon(info.value?.icon));
const otherStepIds = computed(() => Object.keys(props.def.steps).filter((id) => id !== props.stepId));
const knownFields = computed(() => fieldsOf(props.def));
const hasField = (name: string): boolean => !!info.value?.fields.includes(name);
const optionsKind = computed<'menu' | 'choice' | null>(() =>
    info.value?.options === 'menu' || info.value?.options === 'choice' ? info.value.options : null,
);
const showNext = computed(() => !!info.value?.has_next || step.value?.next !== undefined);

// The same flowGraph updaters as the inline editors on the node (design 2026-09-18 §3).
function patch(mutate: (s: FlowStep) => void): void {
    emit('change', updateStep(props.def, props.stepId, mutate));
}

function setText(key: 'text' | 'field' | 'script' | 'case_type' | 'next', value: string): void {
    if (key === 'text') {
        emit('change', setStepText(props.def, props.stepId, value));
        return;
    }
    patch((s) => {
        s[key] = value;
    });
}

function setType(type: string): void {
    const next = props.catalog[type];
    patch((s) => {
        s.type = type;
        if (next?.options === 'menu' || next?.options === 'choice') s.options ??= [];
        if (next?.has_next && s.next === undefined) s.next = '';
        if (next?.fields.includes('field') && !s.field) s.field = props.stepId;
    });
}

function setVerifyOwner(on: boolean): void {
    patch((s) => {
        if (on) s.verify_owner = true;
        else delete s.verify_owner;
    });
}

function setOptions(options: FlowOption[]): void {
    patch((s) => {
        s.options = options;
    });
}

function setBranches(branches: FlowBranch[]): void {
    patch((s) => {
        if (branches.length) s.branches = branches;
        else delete s.branches;
    });
}

function makeStart(): void {
    emit('change', { ...JSON.parse(JSON.stringify(props.def)), start: props.stepId });
}

// Renaming is explicit (Apply) so half-typed ids never rewrite references.
const newId = ref(props.stepId);
watch(
    () => props.stepId,
    (id) => (newId.value = id),
);
const renameError = computed(() => {
    const problem = stepIdProblem(props.def, props.stepId, newId.value);
    return problem ? t(STEP_ID_PROBLEM_KEYS[problem]) : null;
});

function applyRename(): void {
    if (newId.value === props.stepId || renameError.value) return;
    const target = newId.value;
    emit('change', renameStep(props.def, props.stepId, target), target);
}

const typeLabel = (type: string): string => stepTypeLabel(type, props.catalog, locale.value, t);
const scriptKnown = computed(() => !step.value?.script || props.scripts.some((s) => s.key === step.value.script));

const input = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';
const label = 'mb-1 block text-xs font-medium text-muted-foreground';
</script>

<template>
    <div v-if="step" class="space-y-4 p-4">
        <div class="flex items-center gap-2.5">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg" :class="color.tile">
                <component :is="icon" class="size-5" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold">{{ typeLabel(step.type) }}</p>
                <p class="truncate text-2xs text-muted-foreground" dir="ltr">{{ stepId }}</p>
            </div>
            <span v-if="isStart" class="inline-flex items-center gap-1 rounded-full bg-emerald-600 px-2 py-0.5 text-2xs font-semibold text-white">
                <Flag class="size-3" aria-hidden="true" />{{ t('flows.start') }}
            </span>
            <button
                v-else
                type="button"
                class="inline-flex h-7 items-center gap-1 rounded-full border border-input px-2.5 text-2xs font-medium hover:border-emerald-600 hover:text-emerald-700"
                @click="makeStart"
            >
                <Flag class="size-3" aria-hidden="true" />{{ t('flows.make_start') }}
            </button>
        </div>

        <div v-if="errors.length || warnings.length" class="space-y-1.5">
            <p
                v-for="(message, i) in errors"
                :key="`e${i}`"
                class="flex gap-1.5 rounded-md bg-destructive/10 px-2.5 py-1.5 text-xs text-destructive"
                role="alert"
            >
                <CircleAlert class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />{{ message }}
            </p>
            <p
                v-for="(message, i) in warnings"
                :key="`w${i}`"
                class="flex gap-1.5 rounded-md bg-amber-500/10 px-2.5 py-1.5 text-xs text-amber-800 dark:text-amber-200"
            >
                <TriangleAlert class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />{{ message }}
            </p>
        </div>

        <div>
            <label :class="label" for="flow-step-id">{{ t('flows.rename_step') }}</label>
            <div class="flex gap-2">
                <input id="flow-step-id" v-model.trim="newId" :class="input" dir="ltr" maxlength="40" @keydown.enter.prevent="applyRename" />
                <button
                    type="button"
                    class="h-9 shrink-0 rounded-md bg-secondary px-3 text-xs font-semibold disabled:opacity-50"
                    :disabled="newId === stepId || !!renameError"
                    @click="applyRename"
                >
                    {{ t('flows.apply') }}
                </button>
            </div>
            <p v-if="renameError" class="mt-1 text-2xs text-destructive">{{ renameError }}</p>
        </div>

        <div>
            <label :class="label" for="flow-step-type">{{ t('flows.type') }}</label>
            <select id="flow-step-type" :value="step.type" :class="input" @change="setType(($event.target as HTMLSelectElement).value)">
                <option v-for="(_, type) in catalog" :key="type" :value="type">{{ typeLabel(String(type)) }}</option>
            </select>
        </div>

        <div v-if="hasField('text')">
            <label :class="label" for="flow-step-text">{{ t('flows.text') }}</label>
            <textarea
                id="flow-step-text"
                :value="step.text ?? ''"
                rows="4"
                dir="auto"
                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm leading-6"
                @input="setText('text', ($event.target as HTMLTextAreaElement).value)"
            />
            <p v-if="step.type === 'record_case'" class="mt-1 text-2xs text-muted-foreground">{{ t('flows.record_case_text_hint') }}</p>
            <p v-if="step.type === 'script'" class="mt-1 text-2xs text-muted-foreground">{{ t('flows.script_text_hint') }}</p>
            <details class="mt-1.5 rounded-md bg-muted/60 px-2.5 py-1.5 text-2xs text-muted-foreground">
                <summary class="cursor-pointer select-none font-medium">{{ t('flows.placeholders_title') }}</summary>
                <ul class="mt-1 space-y-0.5">
                    <li v-for="p in PLACEHOLDERS" :key="p" class="flex flex-wrap gap-x-1.5">
                        <code class="rounded bg-background px-1 font-mono text-foreground" dir="ltr">{{ '{' + p + '}' }}</code>
                        <span>{{ t(`flows.placeholders.${p}`) }}</span>
                    </li>
                </ul>
            </details>
        </div>
        <p v-if="step.type === 'summary'" class="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">{{ t('flows.summary_hint') }}</p>
        <p v-if="step.type === 'status'" class="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">{{ t('flows.status_hint') }}</p>
        <p v-if="step.type === 'order_items'" class="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
            {{ t('flows.order_items_hint') }}
        </p>
        <p v-if="step.type === 'product_link'" class="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
            {{ t('flows.product_link_hint') }}
        </p>

        <label v-if="hasField('verify_owner')" class="flex items-start gap-2.5 rounded-md bg-muted/60 px-3 py-2.5">
            <input
                type="checkbox"
                class="mt-0.5 size-4 shrink-0 accent-primary"
                :checked="step.verify_owner === true"
                @change="setVerifyOwner(($event.target as HTMLInputElement).checked)"
            />
            <span class="grid gap-0.5">
                <span class="text-xs font-semibold">{{ t('flows.verify_owner') }}</span>
                <span class="text-2xs text-muted-foreground">{{ t('flows.verify_owner_hint') }}</span>
            </span>
        </label>

        <div v-if="hasField('field')">
            <label :class="label" for="flow-step-field">{{ t('flows.field') }}</label>
            <input
                id="flow-step-field"
                :value="step.field ?? ''"
                :class="input"
                dir="ltr"
                list="flow-step-fields"
                @input="setText('field', ($event.target as HTMLInputElement).value)"
            />
            <datalist id="flow-step-fields">
                <option v-for="f in knownFields" :key="f" :value="f" />
            </datalist>
            <p class="mt-1 text-2xs text-muted-foreground">{{ t('flows.field_hint') }}</p>
        </div>

        <div v-if="hasField('case_type')">
            <label :class="label" for="flow-step-case">{{ t('flows.case_type') }}</label>
            <select
                id="flow-step-case"
                :value="step.case_type ?? ''"
                :class="input"
                @change="setText('case_type', ($event.target as HTMLSelectElement).value)"
            >
                <option value="" disabled>{{ t('flows.case_type_none') }}</option>
                <option v-for="c in CASE_TYPES" :key="c" :value="c">{{ t(`cases.types.${c}`) }}</option>
            </select>
        </div>

        <div v-if="hasField('script')">
            <label :class="label" for="flow-step-script">{{ t('flows.script') }}</label>
            <select
                id="flow-step-script"
                :value="step.script ?? ''"
                :class="input"
                @change="setText('script', ($event.target as HTMLSelectElement).value)"
            >
                <option value="" disabled>{{ t('flows.script_none') }}</option>
                <option v-if="!scriptKnown" :value="step.script">{{ step.script }}</option>
                <option v-for="s in scripts" :key="s.key" :value="s.key">{{ s.title }}</option>
            </select>
        </div>

        <section v-if="optionsKind">
            <h3 :class="label">{{ t('flows.options') }}</h3>
            <OptionsEditor
                :model-value="step.options ?? []"
                :kind="optionsKind"
                :step-ids="otherStepIds"
                :flows="flows"
                :scripts="scripts"
                :current-flow-key="flowKey"
                :allow-when="step.type === 'status'"
                @update:model-value="setOptions"
            />
        </section>

        <div v-if="showNext">
            <label :class="label" for="flow-step-next">{{ t('flows.next') }}</label>
            <select id="flow-step-next" :value="step.next ?? ''" :class="input" @change="setText('next', ($event.target as HTMLSelectElement).value)">
                <option value="">{{ t('flows.not_connected') }}</option>
                <option v-for="id in otherStepIds" :key="id" :value="id">{{ id }} · {{ typeLabel(def.steps[id].type) }}</option>
                <option value="end">{{ t('flows.end') }}</option>
            </select>
        </div>

        <section v-if="showNext">
            <h3 :class="label">{{ t('flows.branches') }}</h3>
            <BranchesEditor :model-value="step.branches ?? []" :def="def" :step-ids="otherStepIds" @update:model-value="setBranches" />
        </section>

        <div class="border-t border-border pt-3">
            <button
                type="button"
                class="inline-flex h-9 w-full items-center justify-center gap-1.5 rounded-md border border-destructive/40 text-sm font-medium text-destructive hover:bg-destructive/10 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="isStart"
                :title="isStart ? t('flows.start_cannot_delete') : undefined"
                @click="emit('delete')"
            >
                <Trash2 class="size-4" aria-hidden="true" />{{ t('flows.delete_step') }}
            </button>
            <p v-if="isStart" class="mt-1 text-center text-2xs text-muted-foreground">{{ t('flows.start_cannot_delete') }}</p>
        </div>
    </div>
</template>
