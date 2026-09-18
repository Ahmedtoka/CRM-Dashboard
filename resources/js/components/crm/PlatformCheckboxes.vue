<script setup lang="ts">
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import type { SharedData } from '@/types';
import type { PlatformValue } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';

const props = defineProps<{ modelValue: PlatformValue[]; legend: string; hint?: string; disabled?: boolean }>();
const emit = defineEmits<{ 'update:modelValue': [value: PlatformValue[]] }>();

const page = usePage<SharedData>();

function toggle(value: PlatformValue, checked: boolean): void {
    const set = new Set(props.modelValue);
    if (checked) set.add(value);
    else set.delete(value);
    emit('update:modelValue', [...set]);
}
</script>

<template>
    <fieldset :disabled="disabled">
        <legend class="mb-1 text-sm font-semibold">{{ legend }}</legend>
        <div class="flex flex-wrap gap-2">
            <label v-for="p in page.props.platforms" :key="p.value" class="inline-flex items-center gap-1.5 rounded-md border border-border px-2 py-1 text-xs">
                <input type="checkbox" class="rounded border-input" :checked="modelValue.includes(p.value)" @change="toggle(p.value, ($event.target as HTMLInputElement).checked)" />
                <PlatformBadge :platform="p.value" show-label size="xs" class="border-0" />
            </label>
        </div>
        <p v-if="hint" class="mt-1 text-2xs text-muted-foreground">{{ hint }}</p>
    </fieldset>
</template>
