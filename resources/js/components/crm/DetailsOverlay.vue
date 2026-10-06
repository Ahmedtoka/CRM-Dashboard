<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { X } from 'lucide-vue-next';

/**
 * The details panel opened by itself on a delivered window while the column is closed (C 2.1, G16).
 * Lives inside the thread, between its header and its composer, so «خلصت», the details toggle and
 * «إرسال» are never under it. Not modal: no focus trap; the X, Escape or `i` closes it.
 */
const emit = defineEmits<{ close: [] }>();
const { t } = useI18n();
</script>

<template>
    <div
        class="absolute inset-y-0 end-0 z-30 hidden w-[340px] max-w-full flex-col border-s bg-card shadow-xl xl:flex"
        role="complementary"
        :aria-label="t('thread.customer')"
        data-details-overlay
    >
        <div class="flex h-9 shrink-0 items-center justify-between border-b px-3">
            <span class="text-xs font-semibold">{{ t('thread.customer') }}</span>
            <button
                type="button"
                class="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :aria-label="t('common.close')"
                :title="t('common.close')"
                data-details-overlay-close
                @click="emit('close')"
            >
                <X class="size-4" aria-hidden="true" />
            </button>
        </div>
        <slot />
    </div>
</template>
