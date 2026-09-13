<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { X } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{ modelValue: string[]; placeholder?: string; label: string; id?: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>();

const { t } = useI18n();
const draft = ref('');

// Enter or comma adds a chip; Backspace on an empty field removes the last one.
function add(): void {
    const value = draft.value.trim().replace(/[,،]$/, '').trim();
    if (value && !props.modelValue.includes(value)) emit('update:modelValue', [...props.modelValue, value]);
    draft.value = '';
}

function remove(index: number): void {
    emit(
        'update:modelValue',
        props.modelValue.filter((_, i) => i !== index),
    );
}

function onKeydown(event: KeyboardEvent): void {
    if (event.isComposing) return;
    if (event.key === 'Enter' || event.key === ',' || event.key === '،') {
        event.preventDefault();
        add();
    } else if (event.key === 'Backspace' && !draft.value && props.modelValue.length) {
        remove(props.modelValue.length - 1);
    }
}
</script>

<template>
    <div class="flex min-h-9 flex-wrap items-center gap-1 rounded-md border border-input bg-background px-1.5 py-1 focus-within:ring-2 focus-within:ring-ring">
        <span v-for="(chip, index) in modelValue" :key="chip" class="inline-flex items-center gap-1 rounded bg-muted px-1.5 py-0.5 text-xs" dir="auto">
            {{ chip }}
            <button type="button" class="rounded text-muted-foreground hover:text-foreground" :aria-label="t('ui.remove_item', { item: chip })" @click="remove(index)">
                <X class="size-3" aria-hidden="true" />
            </button>
        </span>
        <input
            :id="id"
            v-model="draft"
            type="text"
            dir="auto"
            class="h-6 min-w-[8rem] flex-1 border-0 bg-transparent px-1 text-sm outline-none focus:ring-0"
            :placeholder="placeholder"
            :aria-label="label"
            @keydown="onKeydown"
            @blur="add"
        />
    </div>
</template>
