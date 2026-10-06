<script setup lang="ts">
/** A reason code (+ text) for send back / return / reject (L 3.1 reason list). */
import FormDialog from '@/components/crm/FormDialog.vue';
import { useI18n } from '@/composables/useI18n';
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{ open: boolean; kind: 'send_back' | 'return' | 'reject'; reasons: string[]; busy?: boolean; error?: string | null }>(),
    {
        busy: false,
        error: null,
    },
);
const emit = defineEmits<{ 'update:open': [open: boolean]; submit: [payload: { code: string; text: string }] }>();

const { t } = useI18n();
const code = ref('');
const text = ref('');
watch(
    () => props.open,
    (o) => {
        if (o) {
            code.value = '';
            text.value = '';
        }
    },
);
const valid = computed(() => code.value !== '' && (code.value !== 'other' || text.value.trim() !== ''));
</script>

<template>
    <FormDialog
        :open="open"
        :title="t(`ads.launch.reason_dialog.${kind}`)"
        :busy="busy"
        :error="error"
        :destructive="kind === 'reject'"
        :disabled="!valid"
        :submit-label="t(`ads.launch.reason_dialog.submit_${kind}`)"
        @update:open="emit('update:open', $event)"
        @submit="emit('submit', { code, text: text.trim() })"
    >
        <fieldset class="space-y-1">
            <legend class="mb-1 text-xs font-medium">{{ t('ads.launch.reason_dialog.code') }}</legend>
            <label v-for="r in reasons" :key="r" class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1 hover:bg-muted">
                <input v-model="code" type="radio" name="launch-reason" :value="r" class="accent-primary" />
                <span>{{ t(`ads.launch.reason.${r}`) }}</span>
            </label>
        </fieldset>
        <label class="block space-y-1">
            <span class="text-xs font-medium">{{ t('ads.launch.reason_dialog.text') }}</span>
            <textarea v-model="text" rows="3" maxlength="1000" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm" />
            <span class="text-2xs text-muted-foreground">{{ t('ads.launch.reason_dialog.text_hint') }}</span>
        </label>
    </FormDialog>
</template>
