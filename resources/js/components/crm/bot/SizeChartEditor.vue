<script setup lang="ts">
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import type { SizeChartData } from '@/types/admin';
import { Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

const model = defineModel<SizeChartData>({ required: true });
const emit = defineEmits<{ save: [] }>();

const { t } = useI18n();
const api = useApi();
const toast = useToast();

const busy = ref(false);
const error = ref<string | null>(null);

function addRow(): void {
    model.value = { ...model.value, rows: [...model.value.rows, model.value.columns.map(() => '')] };
}

function removeRow(index: number): void {
    model.value = { ...model.value, rows: model.value.rows.filter((_, i) => i !== index) };
}

function addColumn(): void {
    model.value = {
        ...model.value,
        columns: [...model.value.columns, ''],
        rows: model.value.rows.map((row) => [...row, '']),
    };
}

function removeColumn(index: number): void {
    model.value = {
        ...model.value,
        columns: model.value.columns.filter((_, i) => i !== index),
        rows: model.value.rows.map((row) => row.filter((_, i) => i !== index)),
    };
}

function setColumn(index: number, value: string): void {
    const columns = [...model.value.columns];
    columns[index] = value;
    model.value = { ...model.value, columns };
}

function setCell(rowIndex: number, colIndex: number, value: string): void {
    const rows = model.value.rows.map((row) => [...row]);
    rows[rowIndex][colIndex] = value;
    model.value = { ...model.value, rows };
}

async function save(): Promise<void> {
    busy.value = true;
    error.value = null;
    try {
        const { data } = await api.put('/settings/bot-knowledge/size-chart', model.value);
        model.value = data.data;
        toast.push(t('settings.bot_knowledge.saved'));
        emit('save');
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="space-y-3">
        <div class="grid grid-cols-2 gap-2 sm:max-w-xs">
            <label class="grid gap-1">
                <span class="text-xs font-medium text-muted-foreground">{{ t('settings.bot_knowledge.unit') }}</span>
                <input v-model="model.unit" dir="ltr" maxlength="10" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm" />
            </label>
        </div>

        <div class="overflow-x-auto rounded-md border border-border">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="bg-surface-accent">
                        <th v-for="(column, colIndex) in model.columns" :key="colIndex" class="p-1.5 text-start">
                            <div class="flex items-center gap-1">
                                <input
                                    :value="column"
                                    dir="auto"
                                    maxlength="40"
                                    class="h-8 w-full min-w-20 rounded-md border border-input bg-background px-2 text-xs font-semibold"
                                    @input="setColumn(colIndex, ($event.target as HTMLInputElement).value)"
                                />
                                <button
                                    v-if="model.columns.length > 2"
                                    type="button"
                                    :title="t('settings.bot_knowledge.remove_column')"
                                    :aria-label="t('settings.bot_knowledge.remove_column')"
                                    class="rounded p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                    @click="removeColumn(colIndex)"
                                >
                                    <Trash2 class="size-3.5" aria-hidden="true" />
                                </button>
                            </div>
                        </th>
                        <th class="w-8 p-1.5"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, rowIndex) in model.rows" :key="rowIndex" class="border-t border-border">
                        <td v-for="(cell, colIndex) in row" :key="colIndex" class="p-1.5">
                            <input
                                :value="cell"
                                dir="auto"
                                maxlength="40"
                                class="h-8 w-full min-w-20 rounded-md border border-input bg-background px-2 text-xs"
                                @input="setCell(rowIndex, colIndex, ($event.target as HTMLInputElement).value)"
                            />
                        </td>
                        <td class="p-1.5 text-end">
                            <button
                                v-if="model.rows.length > 1"
                                type="button"
                                :title="t('settings.bot_knowledge.remove_row')"
                                :aria-label="t('settings.bot_knowledge.remove_row')"
                                class="rounded p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                @click="removeRow(rowIndex)"
                            >
                                <Trash2 class="size-3.5" aria-hidden="true" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap gap-2">
            <button
                type="button"
                class="inline-flex h-8 items-center gap-1 rounded-md border border-input px-2.5 text-xs disabled:pointer-events-none disabled:opacity-50"
                :disabled="model.rows.length >= 20"
                @click="addRow"
            >
                <Plus class="size-3.5" aria-hidden="true" />{{ t('settings.bot_knowledge.add_row') }}
            </button>
            <button
                type="button"
                class="inline-flex h-8 items-center gap-1 rounded-md border border-input px-2.5 text-xs disabled:pointer-events-none disabled:opacity-50"
                :disabled="model.columns.length >= 8"
                @click="addColumn"
            >
                <Plus class="size-3.5" aria-hidden="true" />{{ t('settings.bot_knowledge.add_column') }}
            </button>
        </div>

        <label class="grid gap-1">
            <span class="text-xs font-medium text-muted-foreground">{{ t('settings.bot_knowledge.note') }}</span>
            <input v-model="model.note" dir="auto" maxlength="200" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm" />
        </label>

        <p v-if="error" role="alert" class="text-xs text-destructive">{{ error }}</p>
        <button type="button" class="h-8 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground disabled:opacity-50" :disabled="busy" @click="save">
            {{ t('common.save') }}
        </button>
    </div>
</template>
