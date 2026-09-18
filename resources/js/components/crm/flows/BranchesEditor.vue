<script setup lang="ts">
import ChipsInput from '@/components/crm/ChipsInput.vue';
import { useI18n } from '@/composables/useI18n';
import { choiceValues, fieldsOf } from '@/lib/flows/flowGraph';
import type { FlowBranch, FlowDefinition } from '@/types/flows';
import { GitBranch, Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    modelValue: FlowBranch[];
    def: FlowDefinition;
    stepIds: string[];
}>();

const emit = defineEmits<{ 'update:modelValue': [branches: FlowBranch[]] }>();

const { t } = useI18n();

const fields = computed(() => fieldsOf(props.def));

function update(index: number, patch: Partial<FlowBranch>): void {
    emit(
        'update:modelValue',
        props.modelValue.map((b, i) => (i === index ? { ...b, ...patch } : b)),
    );
}

function setField(index: number, field: string): void {
    // Values belong to a field, so switching the field starts the values over.
    update(index, { field, in: [] });
}

function toggleValue(index: number, value: string): void {
    const current = props.modelValue[index].in ?? [];
    update(index, { in: current.includes(value) ? current.filter((v) => v !== value) : [...current, value] });
}

function add(): void {
    emit('update:modelValue', [...props.modelValue, { field: fields.value[0] ?? '', in: [], next: '' }]);
}

function remove(index: number): void {
    emit(
        'update:modelValue',
        props.modelValue.filter((_, i) => i !== index),
    );
}

const input = 'h-8 w-full rounded-md border border-input bg-background px-2 text-xs';
</script>

<template>
    <div class="space-y-2">
        <p class="text-2xs text-muted-foreground">{{ t('flows.branches_hint') }}</p>

        <div
            v-for="(branch, index) in modelValue"
            :key="index"
            class="space-y-2 rounded-lg border border-dashed border-amber-500/50 bg-amber-500/5 p-2"
        >
            <div class="flex items-center gap-1.5">
                <GitBranch class="size-3.5 text-amber-600" aria-hidden="true" />
                <label class="flex min-w-0 flex-1 items-center gap-1.5">
                    <span class="shrink-0 text-2xs text-muted-foreground">{{ t('flows.branch_field') }}</span>
                    <select :value="branch.field" :class="input" dir="ltr" @change="setField(index, ($event.target as HTMLSelectElement).value)">
                        <option value="" disabled>—</option>
                        <option v-for="f in fields" :key="f" :value="f">{{ f }}</option>
                        <option v-if="branch.field && !fields.includes(branch.field)" :value="branch.field">{{ branch.field }}</option>
                    </select>
                </label>
                <button
                    type="button"
                    class="rounded p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                    :aria-label="t('flows.remove_branch')"
                    :title="t('flows.remove_branch')"
                    @click="remove(index)"
                >
                    <Trash2 class="size-3.5" aria-hidden="true" />
                </button>
            </div>

            <div>
                <span class="mb-1 block text-2xs text-muted-foreground">{{ t('flows.branch_values') }}</span>
                <div v-if="choiceValues(def, branch.field).length" class="flex flex-wrap gap-1">
                    <button
                        v-for="choice in choiceValues(def, branch.field)"
                        :key="choice.value"
                        type="button"
                        class="rounded-full border px-2 py-0.5 text-2xs transition-colors"
                        :class="
                            branch.in?.includes(choice.value)
                                ? 'border-amber-600 bg-amber-500 text-white'
                                : 'border-input bg-background hover:border-amber-500'
                        "
                        :aria-pressed="branch.in?.includes(choice.value)"
                        @click="toggleValue(index, choice.value)"
                    >
                        {{ choice.title }}
                    </button>
                </div>
                <ChipsInput
                    v-else
                    :model-value="branch.in ?? []"
                    :label="t('flows.branch_values')"
                    :placeholder="t('flows.branch_values_free')"
                    @update:model-value="update(index, { in: $event })"
                />
            </div>

            <label class="flex items-center gap-1.5">
                <span class="shrink-0 text-2xs text-muted-foreground">{{ t('flows.branch_next') }}</span>
                <select :value="branch.next" :class="input" @change="update(index, { next: ($event.target as HTMLSelectElement).value })">
                    <option value="">{{ t('flows.not_connected') }}</option>
                    <option v-for="id in stepIds" :key="id" :value="id">{{ id }}</option>
                    <option value="end">{{ t('flows.end') }}</option>
                </select>
            </label>
        </div>

        <button
            type="button"
            class="inline-flex h-8 w-full items-center justify-center gap-1.5 rounded-md border border-dashed border-input text-xs text-muted-foreground hover:border-amber-500 hover:text-amber-700"
            @click="add"
        >
            <Plus class="size-3.5" aria-hidden="true" />{{ t('flows.add_branch') }}
        </button>
    </div>
</template>
