<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { OutboundCardButton, OutboundCards } from '@/types/crm';
import { ExternalLink, Phone } from 'lucide-vue-next';

/**
 * The rich cards a bot message carries (the branch cards, the store-link button — 2026-09-19;
 * app/Channels/Cards/OutboundCards.php). `quiet` is the inbox look: small translucent cards that sit
 * inside the bot bubble like its quick-reply chips; `messenger` is the designer sandbox look (white
 * cards in a sideways carousel, as the customer sees them on Messenger).
 */
const props = withDefaults(defineProps<{ cards: OutboundCards; variant?: 'quiet' | 'messenger' }>(), { variant: 'quiet' });

const { t } = useI18n();

function href(b: OutboundCardButton): string | undefined {
    if (b.type === 'web_url') return b.url;
    return b.phone ? `tel:${b.phone}` : undefined;
}

const quiet = props.variant === 'quiet';
</script>

<template>
    <div v-if="cards.type === 'generic'" class="-mx-1 flex snap-x gap-1.5 overflow-x-auto px-1 pb-1" role="list" :aria-label="t('thread.cards')">
        <article
            v-for="(card, i) in cards.cards"
            :key="i"
            role="listitem"
            class="w-44 shrink-0 snap-start overflow-hidden"
            :class="
                quiet
                    ? 'rounded-lg bg-white/15 text-2xs leading-4 ring-1 ring-white/25 dark:bg-black/15 dark:ring-white/10'
                    : 'rounded-xl bg-card text-xs shadow-sm ring-1 ring-border'
            "
        >
            <div :class="quiet ? 'px-2 py-1.5' : 'px-2.5 py-2'">
                <p class="truncate font-semibold" dir="auto">{{ card.title }}</p>
                <p v-if="card.subtitle" class="mt-0.5 line-clamp-3 whitespace-pre-line opacity-80" dir="auto">{{ card.subtitle }}</p>
            </div>
            <div v-if="card.buttons.length" class="grid border-t" :class="quiet ? 'border-white/20 dark:border-white/10' : 'border-border'">
                <a
                    v-for="(b, j) in card.buttons"
                    :key="j"
                    :href="href(b)"
                    target="_blank"
                    rel="noopener noreferrer"
                    dir="auto"
                    class="flex items-center justify-center gap-1 truncate px-2 py-1 text-center font-medium hover:underline"
                    :class="[
                        j > 0 && (quiet ? 'border-t border-white/20 dark:border-white/10' : 'border-t border-border'),
                        quiet ? 'opacity-90' : 'text-primary',
                    ]"
                >
                    {{ b.title }}
                </a>
            </div>
        </article>
    </div>

    <div v-else class="flex flex-wrap gap-1">
        <a
            v-for="(b, j) in cards.buttons"
            :key="j"
            :href="href(b)"
            target="_blank"
            rel="noopener noreferrer"
            dir="auto"
            class="inline-flex items-center gap-1 font-medium hover:underline"
            :class="
                quiet
                    ? 'rounded-full bg-white/20 px-1.5 py-px text-2xs leading-4 opacity-80 ring-1 ring-white/30 dark:bg-black/20 dark:ring-white/15'
                    : 'rounded-lg bg-card px-3 py-1.5 text-xs text-primary shadow-sm ring-1 ring-border'
            "
        >
            <component :is="b.type === 'phone' ? Phone : ExternalLink" class="size-3 shrink-0" aria-hidden="true" />{{ b.title }}
        </a>
    </div>
</template>
