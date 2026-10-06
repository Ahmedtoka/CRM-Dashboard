<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { Button } from '@/components/ui/button';
import { onMounted, ref } from 'vue';

defineProps<{ placeholder: string; submitLabel: string; busy: boolean }>();
const emit = defineEmits<{ submit: [text: string]; cancel: [] }>();

const { t } = useI18n();
const text = ref('');
const field = ref<HTMLTextAreaElement | null>(null);

onMounted(() => field.value?.focus());

function submit(): void {
    const value = text.value.trim();
    if (value) emit('submit', value);
}

// Ctrl/Cmd+Enter sends, Esc cancels.
function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
        event.preventDefault();
        submit();
    } else if (event.key === 'Escape') {
        emit('cancel');
    }
}
</script>

<template>
    <form class="mt-2 space-y-1.5" @submit.prevent="submit">
        <textarea
            ref="field"
            v-model="text"
            rows="2"
            dir="auto"
            maxlength="2000"
            class="w-full resize-y rounded-2xl border border-input bg-elevated px-3 py-1.5 text-sm"
            :placeholder="placeholder"
            :aria-label="placeholder"
            @keydown="onKeydown"
        />
        <div class="flex gap-2">
            <Button type="submit" size="sm" class="h-7 px-3 text-xs font-medium" :loading="busy" :disabled="!text.trim()">{{ submitLabel }}</Button>
            <button type="button" class="h-7 rounded-md border px-3 text-xs hover:bg-muted" @click="emit('cancel')">{{ t('common.cancel') }}</button>
        </div>
    </form>
</template>
