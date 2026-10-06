<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { CircleAlert, RotateCw } from 'lucide-vue-next';

/** A failed partial load, in place, with one retry. Action results stay toasts (ToastStack). */
withDefaults(defineProps<{ message?: string; retrying?: boolean }>(), { message: undefined, retrying: false });
const emit = defineEmits<{ retry: [] }>();
const { t } = useI18n();
</script>

<template>
    <div role="alert" class="flex flex-wrap items-center gap-2 rounded-lg bg-destructive/10 px-3 py-2 text-xs text-destructive">
        <CircleAlert class="size-4 shrink-0" aria-hidden="true" />
        <span class="min-w-0 flex-1">{{ message ?? t('ui.load_failed') }}</span>
        <Button type="button" variant="outline" size="sm" :loading="retrying" @click="emit('retry')"
            ><RotateCw aria-hidden="true" />{{ t('ui.retry') }}</Button
        >
    </div>
</template>
