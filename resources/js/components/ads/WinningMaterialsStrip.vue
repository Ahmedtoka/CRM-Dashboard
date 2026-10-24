<script setup lang="ts">
/** The winners library (round 3): a horizontal strip of the materials whose ads win, each with its 30-day numbers and «reuse». */
import WinnerBadge from '@/components/ads/WinnerBadge.vue';
import { buttonVariants } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { formatAdsMoney, formatRoas } from '@/lib/ads';
import { formatCount } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { WinningMaterial } from '@/types/adsLibrary';
import { ImageOff, Rocket, Trophy } from 'lucide-vue-next';

defineProps<{ winners: WinningMaterial[] }>();
const emit = defineEmits<{ reuse: [id: number] }>();
const { t, locale } = useI18n();

const results = (w: WinningMaterial): string =>
    t('ads.library_r3.results', {
        spend: formatAdsMoney(w.performance.spend_tax, locale.value),
        orders: formatCount(w.performance.real_orders, locale.value),
        roas: formatRoas(w.performance.roas, locale.value),
    });
</script>

<template>
    <section data-test="winners-strip" class="rounded-lg border border-line bg-card" aria-labelledby="winners-strip-title">
        <header class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 px-4 pt-3">
            <h2 id="winners-strip-title" class="flex items-center gap-1.5 text-sm font-semibold text-foreground">
                <Trophy class="size-4 text-ok" aria-hidden="true" />{{ t('ads.library_r3.winners_title') }}
            </h2>
            <p class="text-xs text-fg-3">{{ t('ads.library_r3.winners_hint') }}</p>
        </header>
        <ul class="flex snap-x gap-3 overflow-x-auto px-4 pb-4 pt-3">
            <li v-for="w in winners" :key="w.id" data-test="winner-card" class="flex w-56 shrink-0 snap-start flex-col overflow-hidden rounded-lg border border-line bg-background">
                <div class="h-36 w-full overflow-hidden bg-sunken">
                    <img v-if="w.thumbnail" :src="w.thumbnail" alt="" class="h-full w-full object-cover" loading="lazy" />
                    <div v-else class="flex h-full items-center justify-center text-fg-3"><ImageOff class="size-6" aria-hidden="true" /></div>
                </div>
                <div class="flex flex-1 flex-col gap-1.5 p-3">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="min-w-0 truncate text-sm font-semibold text-foreground" dir="auto" :title="w.title">{{ w.title }}</h3>
                        <WinnerBadge v-if="w.performance.winner_tier" :tier="w.performance.winner_tier" />
                    </div>
                    <p v-if="w.product_title" class="truncate text-xs text-fg-2" dir="auto" :title="w.product_title">{{ w.product_title }}</p>
                    <p class="text-xs tabular-nums text-fg-2">{{ results(w) }}</p>
                    <button
                        v-if="w.can_reuse"
                        type="button"
                        data-test="winner-reuse"
                        :class="cn(buttonVariants({ variant: 'default', size: 'sm' }), 'mt-auto w-full gap-1.5')"
                        @click="emit('reuse', w.id)"
                    >
                        <Rocket class="size-3.5" aria-hidden="true" />{{ t('ads.library_r3.reuse') }}
                    </button>
                </div>
            </li>
        </ul>
    </section>
</template>
