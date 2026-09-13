<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { Link } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, Info, X } from 'lucide-vue-next';

const { toasts, dismiss } = useToast();
const { t } = useI18n();

const icons = { success: CircleCheck, error: CircleAlert, info: Info };
</script>

<template>
    <div class="pointer-events-none fixed bottom-4 end-4 z-[60] flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2" aria-live="polite">
        <div
            v-for="toast in toasts"
            :key="toast.id"
            class="pointer-events-auto flex items-start gap-2 rounded-lg border bg-card px-3 py-2 text-xs shadow-lg"
            :class="{ 'border-red-200': toast.tone === 'error' }"
            :role="toast.tone === 'error' ? 'alert' : 'status'"
        >
            <component
                :is="icons[toast.tone]"
                class="mt-0.5 size-4 shrink-0"
                :class="{ 'text-emerald-600': toast.tone === 'success', 'text-red-600': toast.tone === 'error', 'text-muted-foreground': toast.tone === 'info' }"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1">
                <p class="break-words text-foreground">{{ toast.message }}</p>
                <Link v-if="toast.link" :href="toast.link.href" class="mt-0.5 inline-block font-medium text-primary hover:underline">{{ toast.link.label }}</Link>
            </div>
            <button type="button" class="rounded p-0.5 text-muted-foreground hover:text-foreground" :aria-label="t('common.close')" @click="dismiss(toast.id)">
                <X class="size-3.5" aria-hidden="true" />
            </button>
        </div>
    </div>
</template>
