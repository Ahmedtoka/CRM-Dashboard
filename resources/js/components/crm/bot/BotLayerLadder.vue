<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatCount } from '@/lib/format';
import { BOT_LAYERS, type BotLayer } from '@/types/botBrain';
import { BookOpenText, Bot, GitBranch, ListChecks, ShieldAlert, UserRound } from 'lucide-vue-next';
import type { Component } from 'vue';

/**
 * «البوت بيختار الرد إزاي» (b2): the six layers a message goes through, in order — the first that
 * can answer does. With `counts` it shows each layer's replies; with `active` it marks the layer
 * that answered one reply (the trace).
 */
const props = withDefaults(
    defineProps<{ counts?: Partial<Record<BotLayer, number>> | null; active?: BotLayer | null; compact?: boolean; hints?: boolean }>(),
    {
        counts: null,
        active: null,
        compact: false,
        hints: false,
    },
);

const { t, locale } = useI18n();

const icons: Record<BotLayer, Component> = {
    script: ListChecks,
    rule: BookOpenText,
    flow: GitBranch,
    agent: Bot,
    fallback: ShieldAlert,
    handover: UserRound,
};

const isActive = (layer: BotLayer) => props.active === layer;
const isPast = (layer: BotLayer) => props.active !== null && BOT_LAYERS.indexOf(layer) < BOT_LAYERS.indexOf(props.active);
</script>

<template>
    <ol
        class="grid gap-1.5"
        :class="compact ? 'grid-cols-6' : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6'"
        :aria-label="t('bot_brain.brain.ladder_title')"
        data-testid="bot-layer-ladder"
    >
        <li
            v-for="(layer, i) in BOT_LAYERS"
            :key="layer"
            class="relative flex min-w-0 flex-col rounded-md border"
            :class="[
                compact ? 'items-center gap-1 px-1 py-1.5 text-center' : 'gap-1 px-3 py-2.5',
                isActive(layer) ? 'border-brand bg-brand-soft text-brand-ink' : 'border-line bg-card',
                isPast(layer) && 'opacity-60',
            ]"
            :aria-current="isActive(layer) ? 'step' : undefined"
            :data-layer="layer"
        >
            <span class="flex items-center gap-1.5" :class="compact && 'flex-col gap-0.5'">
                <span
                    v-if="!compact"
                    class="flex size-5 shrink-0 items-center justify-center rounded-full bg-sunken text-[11px] font-semibold tabular-nums text-fg-2"
                    aria-hidden="true"
                    >{{ formatCount(i + 1, locale) }}</span
                >
                <component :is="icons[layer]" class="size-4 shrink-0" :class="isActive(layer) ? 'text-brand-ink' : 'text-fg-3'" aria-hidden="true" />
                <span class="truncate font-semibold" :class="compact ? 'text-[11px] leading-tight' : 'text-13'">{{
                    t(`bot_brain.brain.layers.${layer}`)
                }}</span>
            </span>
            <span v-if="counts" class="font-bold tabular-nums" :class="compact ? 'text-xs' : 'text-xl'" :data-count="counts[layer] ?? 0">{{
                formatCount(counts[layer] ?? 0, locale)
            }}</span>
            <span v-if="hints && !compact" class="text-12 leading-snug text-fg-2">{{ t(`bot_brain.brain.layer_hints.${layer}`) }}</span>
        </li>
    </ol>
</template>
