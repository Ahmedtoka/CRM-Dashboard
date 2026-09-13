<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { LoaderCircle } from 'lucide-vue-next';
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

// Ctrl/⌘+Enter sends, Esc cancels.
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
            class="w-full resize-y rounded-md border border-input bg-background px-2 py-1.5 text-sm"
            :placeholder="placeholder"
            :aria-label="placeholder"
            @keydown="onKeydown"
        />
        <div class="flex gap-2">
            <button type="submit" class="inline-flex h-7 items-center gap-1 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground disabled:opacity-50" :disabled="busy || !text.trim()">
                <LoaderCircle v-if="busy" class="size-3 animate-spin" aria-hidden="true" />{{ submitLabel }}
            </button>
            <button type="button" class="h-7 rounded-md border px-3 text-xs hover:bg-muted" @click="emit('cancel')">{{ t('common.cancel') }}</button>
        </div>
    </form>
</template>
