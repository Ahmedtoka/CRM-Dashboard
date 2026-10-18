<script setup lang="ts">
import { useAppearance } from '@/composables/useAppearance';
import { useI18n } from '@/composables/useI18n';
import { Monitor, Moon, Sun } from 'lucide-vue-next';

interface Props {
    class?: string;
}

const { class: containerClass = '' } = defineProps<Props>();

const { appearance, updateAppearance } = useAppearance();

const { t } = useI18n();

/** The three looks as big choice buttons (mockup `.choice`): the chosen one is mauve, aria-pressed says it to a reader. */
const tabs = [
    { value: 'light', Icon: Sun, label: 'nav.appearance_light' },
    { value: 'dark', Icon: Moon, label: 'nav.appearance_dark' },
    { value: 'system', Icon: Monitor, label: 'nav.appearance_system' },
] as const;
</script>

<template>
    <div :class="['grid grid-cols-1 gap-2 sm:grid-cols-3', containerClass]" role="group" :aria-label="t('nav.appearance')">
        <button
            v-for="{ value, Icon, label } in tabs"
            :key="value"
            type="button"
            :aria-pressed="appearance === value"
            :data-appearance="value"
            @click="updateAppearance(value)"
            :class="[
                'flex min-h-12 items-center justify-center gap-2 rounded-md border px-4 py-2.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                appearance === value
                    ? 'border-brand bg-brand-soft text-brand-ink'
                    : 'border-line-strong bg-card text-fg-2 hover:bg-surface-2 hover:text-foreground',
            ]"
        >
            <component :is="Icon" class="size-[18px]" aria-hidden="true" />
            <span>{{ t(label) }}</span>
        </button>
    </div>
</template>
