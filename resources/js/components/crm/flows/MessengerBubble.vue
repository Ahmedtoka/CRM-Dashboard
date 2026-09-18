<script lang="ts">
/** Messenger quick-reply chip look, shared by the sandbox chat and the step preview. */
export const MESSENGER_CHIP_CLASS =
    'rounded-full border border-primary/40 bg-card px-3 py-1 text-xs font-medium text-primary transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary';
</script>

<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { Bot } from 'lucide-vue-next';

/**
 * One Messenger-style message (design 2026-09-18 §5): the bot on the start side with its
 * avatar, the customer on the end side. Quick-reply chips go in the default slot, under
 * the bot bubble. `dashed` draws a placeholder bubble (what the customer is expected to send).
 */
withDefaults(defineProps<{ side?: 'bot' | 'customer'; text?: string; showAvatar?: boolean; dashed?: boolean }>(), {
    side: 'bot',
    text: '',
    showAvatar: true,
    dashed: false,
});

const { t } = useI18n();
</script>

<template>
    <div v-if="side === 'bot'" class="flex items-end gap-1.5">
        <span
            class="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
            :class="showAvatar ? '' : 'invisible'"
            :aria-label="t('flows.test_bot')"
        >
            <Bot class="size-3.5" aria-hidden="true" />
        </span>
        <div class="flex max-w-[82%] flex-col items-start gap-1.5">
            <p
                v-if="text"
                dir="auto"
                class="whitespace-pre-line break-words rounded-2xl rounded-es-md bg-card px-3 py-2 text-sm leading-relaxed text-foreground shadow-sm ring-1 ring-border"
            >
                {{ text }}
            </p>
            <slot />
        </div>
    </div>

    <div v-else class="flex justify-end">
        <p
            dir="auto"
            class="max-w-[82%] whitespace-pre-line break-words rounded-2xl rounded-ee-md px-3 py-2 text-sm leading-relaxed"
            :class="dashed ? 'border border-dashed border-primary/50 bg-primary/5 text-primary' : 'bg-primary text-primary-foreground shadow-sm'"
        >
            {{ text }}
        </p>
    </div>
</template>
