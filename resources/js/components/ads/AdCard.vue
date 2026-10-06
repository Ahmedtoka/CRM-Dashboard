<script setup lang="ts">
/** Card view of one ad (U 3.2): 4 numbers max — spend, real ROAS, result chain, sparkline. Stop is a 44 px button. */
import AdStatusButton from '@/components/ads/AdStatusButton.vue';
import CreativeThumb from '@/components/ads/CreativeThumb.vue';
import HealthBadge from '@/components/ads/HealthBadge.vue';
import Sparkline from '@/components/ads/Sparkline.vue';
import { useI18n } from '@/composables/useI18n';
import { formatAdsMoney, formatRoas, isAdActive } from '@/lib/ads';
import { formatCount } from '@/lib/format';
import type { AdRowData } from '@/types/ads';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ row: AdRowData; currency?: string; dataAt?: string | null }>(), { currency: 'EGP', dataAt: null });
const emit = defineEmits<{ open: [id: number]; done: [status: 'active' | 'paused'] }>();
const { t, locale } = useI18n();
const cur = computed(() => props.row.currency || props.currency);
const n = (v: number) => formatCount(v, locale.value);
const active = computed(() => isAdActive(props.row));
const result = computed(() =>
    props.row.objective === 'messages'
        ? t('ads.control.row.chats_orders', { chats: n(props.row.conversations), orders: n(props.row.real_orders) })
        : props.row.objective === 'traffic'
          ? t('ads.control.row.clicks', { n: n(props.row.clicks) })
          : t('ads.control.row.purchases_orders', { purchases: n(props.row.purchases), orders: n(props.row.real_orders) }),
);
</script>

<template>
    <article class="flex min-w-0 flex-col overflow-hidden rounded-lg bg-card shadow-card">
        <!-- Phones: the preview is capped (15rem, cropped to fill) so two cards fit a screen; md+ keeps the 4:5 frame. -->
        <button
            type="button"
            data-test="card-media"
            class="aspect-[4/5] max-h-60 w-full overflow-hidden bg-muted md:max-h-none"
            :aria-label="t('ads.control.row.preview')"
            @click="emit('open', row.id)"
        >
            <CreativeThumb :ad="row" size="fill" :show-pills="false" square class="max-md:aspect-auto max-md:h-full" />
        </button>
        <div class="flex flex-1 flex-col gap-2 p-3">
            <div class="flex items-center justify-between gap-2">
                <div class="flex flex-wrap gap-1"><HealthBadge v-for="h in row.health.slice(0, 2)" :key="h" :kind="h" /></div>
                <span class="inline-flex shrink-0 items-center gap-1 text-2xs">
                    <span class="size-1.5 rounded-full" :class="active ? 'bg-success' : 'bg-muted-foreground'" aria-hidden="true" />
                    {{ active ? t('ads.control.row.running') : t('ads.control.row.stopped') }}
                </span>
            </div>
            <h3 class="truncate text-sm font-semibold" dir="auto" :title="row.name">{{ row.name }}</h3>
            <p class="truncate text-2xs text-muted-foreground">
                <span dir="auto">{{ row.account }}</span
                ><template v-if="row.buyer">
                    · <span dir="auto">{{ row.buyer }}</span></template
                >
                ·
                {{ t(`ads.control.objective.${row.objective}`) }}
            </p>
            <button type="button" class="block w-full space-y-1 text-start hover:underline" @click="emit('open', row.id)">
                <span class="grid grid-cols-2 gap-1 text-xs tabular-nums">
                    <span>
                        <span class="block text-2xs text-muted-foreground">{{ t('ads.control.col.spend') }}</span>
                        <span class="block font-medium">{{ formatAdsMoney(row.spend_tax, locale, cur) }}</span>
                    </span>
                    <span>
                        <span class="block text-2xs text-muted-foreground">{{ t('ads.control.today.real_roas') }}</span>
                        <span class="block font-semibold">{{ formatRoas(row.real_roas, locale) }}</span>
                    </span>
                </span>
                <span class="block text-xs">{{ result }}</span>
            </button>
            <Sparkline :points="row.series" :currency="cur" :width="200" />
            <div class="mt-auto flex items-center justify-between gap-2 pt-1">
                <button type="button" class="h-11 rounded-md px-3 text-xs text-primary hover:bg-muted" @click="emit('open', row.id)">
                    {{ t('ads.control.row.why') }}
                </button>
                <AdStatusButton
                    size="md"
                    :account-id="row.account_id"
                    :account="row.account"
                    :platform="row.platform"
                    level="ad"
                    :external-id="row.external_id"
                    :name="row.name"
                    :status="row.status"
                    :can-write="row.can_write"
                    :spend-today="row.spend_today"
                    :data-at="dataAt"
                    :currency="cur"
                    :parent-paused="row.parent_paused"
                    @done="emit('done', $event)"
                />
            </div>
        </div>
    </article>
</template>
