<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { TodayMode } from '@/types/today';
import { Link } from '@inertiajs/vue3';

defineProps<{ mode: TodayMode }>();
const { t } = useI18n();
const days = [
    { mode: 'today' as const, href: '/today' },
    { mode: 'yesterday' as const, href: '/today?day=yesterday' },
];
</script>

<template>
    <nav class="inline-flex gap-0.5 rounded-md bg-sunken p-0.5 text-sm" :aria-label="t('today.day_label')">
        <Link
            v-for="d in days"
            :key="d.mode"
            :href="d.href"
            preserve-scroll
            class="inline-flex min-h-[30px] items-center whitespace-nowrap rounded-[6px] px-2.5 font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            :class="mode === d.mode ? 'bg-card text-foreground shadow-e1' : 'text-fg-2 hover:text-foreground'"
            :aria-current="mode === d.mode ? 'page' : undefined"
        >
            {{ t(`today.toggle.${d.mode}`) }}
        </Link>
    </nav>
</template>
