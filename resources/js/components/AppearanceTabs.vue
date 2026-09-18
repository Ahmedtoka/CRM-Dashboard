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

const tabs = [
    { value: 'light', Icon: Sun, label: 'nav.appearance_light' },
    { value: 'dark', Icon: Moon, label: 'nav.appearance_dark' },
    { value: 'system', Icon: Monitor, label: 'nav.appearance_system' },
] as const;
</script>

<template>
    <div :class="['inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800', containerClass]">
        <button
            v-for="{ value, Icon, label } in tabs"
            :key="value"
            type="button"
            :aria-pressed="appearance === value"
            @click="updateAppearance(value)"
            :class="[
                'flex items-center rounded-md px-3.5 py-1.5 transition-colors',
                appearance === value
                    ? 'bg-white shadow-sm dark:bg-neutral-700 dark:text-neutral-100'
                    : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
            ]"
        >
            <component :is="Icon" class="-ms-1 h-4 w-4" aria-hidden="true" />
            <span class="ms-1.5 text-sm">{{ t(label) }}</span>
        </button>
    </div>
</template>
