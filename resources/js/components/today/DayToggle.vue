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
    <nav class="inline-flex rounded-md border border-border bg-card p-0.5 text-sm" :aria-label="t('today.day_label')">
        <Link
            v-for="d in days"
            :key="d.mode"
            :href="d.href"
            preserve-scroll
            class="rounded px-3 py-1.5 font-medium"
            :class="mode === d.mode ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'"
            :aria-current="mode === d.mode ? 'page' : undefined"
        >
            {{ t(`today.toggle.${d.mode}`) }}
        </Link>
    </nav>
</template>
