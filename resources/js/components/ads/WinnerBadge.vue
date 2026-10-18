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
            winner: { icon: Trophy, cls: 'bg-ok-soft text-ok' },
            promising: { icon: TrendingUp, cls: 'bg-warn-soft text-warn' },
            loser: { icon: TrendingDown, cls: 'bg-danger-soft text-danger' },
            neutral: { icon: Sparkles, cls: 'bg-sunken text-fg-2' },
        })[props.tier],
);
</script>

<template>
    <span class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-full px-2 text-xs font-semibold" :class="look.cls">
        <component :is="look.icon" class="size-3" aria-hidden="true" />
        {{ t(`ads.tier.${tier}`) }}
    </span>
</template>
