<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { WinnerTier } from '@/types/ads';
import { Sparkles, TrendingDown, TrendingUp, Trophy } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ tier: WinnerTier }>();

const { t } = useI18n();

const look = computed(
    () =>
        ({
            winner: { icon: Trophy, cls: 'bg-success text-white' },
            promising: { icon: TrendingUp, cls: 'bg-warning text-amber-950' },
            loser: { icon: TrendingDown, cls: 'bg-destructive text-white' },
            neutral: { icon: Sparkles, cls: 'bg-muted text-muted-foreground' },
        })[props.tier],
);
</script>

<template>
    <span class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-full px-2 text-2xs font-bold shadow-sm" :class="look.cls">
        <component :is="look.icon" class="size-3" aria-hidden="true" />
        {{ t(`ads.tier.${tier}`) }}
    </span>
</template>
