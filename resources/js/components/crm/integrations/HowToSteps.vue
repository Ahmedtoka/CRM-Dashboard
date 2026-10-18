<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { ChevronDown } from 'lucide-vue-next';

/** "Before you start" prerequisites with the step-by-step "how" folded away. */
defineProps<{ steps: string[]; open?: boolean }>();

const { t } = useI18n();
</script>

<template>
    <details class="group rounded-md border border-line bg-card text-xs" :open="open">
        <summary
            class="flex cursor-pointer list-none items-center gap-2 rounded-md px-3 py-2 font-medium hover:bg-sunken focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring [&::-webkit-details-marker]:hidden"
        >
            <span class="flex-1">{{ t('settings.integrations.before_you_start') }}</span>
            <span class="text-fg-2">{{ t('settings.integrations.how_to') }}</span>
            <ChevronDown class="size-3.5 text-fg-2 transition-transform duration-200 group-open:rotate-180" aria-hidden="true" />
        </summary>
        <ol class="grid gap-2 border-t border-line px-3 py-2.5">
            <li v-for="(step, i) in steps" :key="i" class="flex gap-2 leading-relaxed">
                <span class="grid size-5 shrink-0 place-items-center rounded-full bg-sunken text-2xs font-semibold tabular-nums text-fg-2">{{
                    i + 1
                }}</span>
                <span class="min-w-0 pt-0.5">{{ step }}</span>
            </li>
        </ol>
    </details>
</template>
