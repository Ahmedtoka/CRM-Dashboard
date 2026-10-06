<script setup lang="ts">
/** G3 in-page re-auth: the password once, valid 15 minutes for approve and bulk approve (A6). */
import FormDialog from '@/components/crm/FormDialog.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { ref, watch } from 'vue';

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ 'update:open': [open: boolean]; confirmed: [] }>();

const api = useApi();
const { t } = useI18n();
const password = ref('');
const busy = ref(false);
const error = ref<string | null>(null);
watch(
    () => props.open,
    (o) => {
        if (o) {
            password.value = '';
            error.value = null;
        }
    },
);

async function submit(): Promise<void> {
    busy.value = true;
    error.value = null;
    try {
        await api.post('/ads/reauth', { password: password.value });
        emit('update:open', false);
        emit('confirmed');
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        busy.value = false;
        password.value = '';
    }
}
</script>

<template>
    <FormDialog
        :open="open"
        :title="t('ads.launch.reauth.title')"
        :description="t('ads.launch.reauth.description')"
        :busy="busy"
        :error="error"
        :disabled="password === ''"
        :submit-label="t('ads.launch.reauth.submit')"
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <label class="block space-y-1">
            <span class="text-xs font-medium">{{ t('ads.launch.reauth.password') }}</span>
            <input
                v-model="password"
                type="password"
                dir="ltr"
                autocomplete="current-password"
                required
                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            />
        </label>
    </FormDialog>
</template>
