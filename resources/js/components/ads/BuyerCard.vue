<script setup lang="ts">
/** One media buyer's scorecard. The «unassigned» card (buyer_id null) is not a link. */
import MoneyCell from '@/components/ads/MoneyCell.vue';
import PlatformChip from '@/components/ads/PlatformChip.vue';
import { useI18n } from '@/composables/useI18n';
import { flowArrow, formatAdsMoney, formatPct, formatQty, formatRoas } from '@/lib/ads';
import { formatCount } from '@/lib/format';
import type { BuyerCardData } from '@/types/ads';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ card: BuyerCardData; href?: string | null; currency?: string }>(), { href: null, currency: 'EGP' });

const { t, locale } = useI18n();
const money = (v: number | null) => formatAdsMoney(v, locale.value, props.currency);

const budgetPct = computed(() => props.card.budget_used_pct);
const remaining = computed(() => (props.card.budget === null ? null : Math.max(0, props.card.budget - props.card.spend)));
const overBudget = computed(() => (budgetPct.value ?? 0) > 100);
const meetsTarget = computed(() => props.card.target_roas !== null && props.card.roas !== null && props.card.roas >= props.card.target_roas);
const convRate = computed(() => (props.card.conversations > 0 ? props.card.conversations_ordered / props.card.conversations : null));
const dot = computed(() => props.card.color ?? 'hsl(var(--muted-foreground))');
</script>

<template>
    <component
        :is="href ? Link : 'article'"
        :href="href ?? undefined"
        class="flex flex-col gap-3 rounded-lg border-t-4 bg-card p-4 text-start shadow-card transition-shadow"
        :class="href ? 'hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-ring' : ''"
        :style="{ borderTopColor: dot }"
    >
        <header class="flex items-start justify-between gap-2">
            <div class="flex min-w-0 items-center gap-2">
                <span class="size-2.5 shrink-0 rounded-full" :style="{ backgroundColor: dot }" aria-hidden="true" />
                <h3 class="truncate text-sm font-bold text-foreground">{{ card.name }}</h3>
            </div>
            <MoneyCell :amount="card.spend" :with-tax="card.spend_tax" :currency="currency" />
        </header>

        <div v-if="card.accounts.length" class="flex flex-wrap gap-1">
            <PlatformChip v-for="a in card.accounts.slice(0, 4)" :key="a.id" :platform="a.platform" size="xs">
                <span class="max-w-32 truncate text-muted-foreground" dir="auto">{{ a.name }}</span>
            </PlatformChip>
            <span v-if="card.accounts.length > 4" class="inline-flex h-5 items-center rounded-full bg-muted px-1.5 text-2xs text-muted-foreground">
                +{{ formatCount(card.accounts.length - 4, locale) }}
            </span>
        </div>

        <div>
            <div class="mb-1 flex items-baseline justify-between gap-2 text-2xs text-muted-foreground">
                <span>{{ t('ads.buyers.budget') }}</span>
                <span v-if="card.budget !== null" class="tabular-nums" :class="overBudget ? 'font-semibold text-destructive' : ''">
                    {{ formatPct((budgetPct ?? 0) / 100, locale, 0) }} · {{ t('ads.buyers.remaining', { amount: money(remaining) }) }}
                </span>
                <span v-else>{{ t('ads.buyers.no_budget') }}</span>
            </div>
            <div
                class="h-2 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                :aria-label="t('ads.buyers.budget')"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-valuenow="Math.min(100, Math.round(budgetPct ?? 0))"
            >
                <div
                    class="h-full rounded-full"
                    :class="overBudget ? 'bg-destructive' : 'bg-primary'"
                    :style="{ width: `${Math.min(100, budgetPct ?? 0)}%` }"
                />
            </div>
        </div>

        <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
            <div>
                <dt class="text-2xs text-muted-foreground">{{ t('ads.kpi.roas') }}</dt>
                <dd
                    class="font-bold tabular-nums"
                    :class="
                        card.target_roas === null ? 'text-foreground' : meetsTarget ? 'text-emerald-700 dark:text-emerald-300' : 'text-destructive'
                    "
                >
                    {{ formatRoas(card.roas, locale) }}
                    <span v-if="card.target_roas !== null" class="text-2xs font-normal text-muted-foreground"
                        >/ {{ formatRoas(card.target_roas, locale) }}</span
                    >
                </dd>
            </div>
            <div>
                <dt class="text-2xs text-muted-foreground">{{ t('ads.kpi.meta_orders') }}</dt>
                <dd class="font-bold tabular-nums">{{ formatQty(card.purchases, locale) }}</dd>
            </div>
            <div>
                <dt class="text-2xs text-muted-foreground">{{ t('ads.kpi.real_orders') }}</dt>
                <dd class="font-bold tabular-nums">
                    {{ formatCount(card.real_orders, locale) }}
                    <span class="block text-2xs font-normal text-muted-foreground">{{ money(card.real_revenue) }}</span>
                </dd>
            </div>
            <div>
                <dt class="text-2xs text-muted-foreground">{{ t('ads.kpi.conversations') }}</dt>
                <dd class="font-bold tabular-nums">
                    {{ formatCount(card.conversations, locale) }} {{ flowArrow(locale) }} {{ formatCount(card.conversations_ordered, locale) }}
                    <span class="block text-2xs font-normal text-muted-foreground">{{
                        t('ads.buyers.conversion', { pct: formatPct(convRate, locale, 1) })
                    }}</span>
                </dd>
            </div>
            <div>
                <dt class="text-2xs text-muted-foreground">{{ t('ads.kpi.cpa') }}</dt>
                <dd class="font-bold tabular-nums">{{ money(card.cpa) }}</dd>
            </div>
            <div>
                <dt class="text-2xs text-muted-foreground">{{ t('ads.kpi.ctr') }}</dt>
                <dd class="font-bold tabular-nums">{{ formatPct(card.ctr, locale) }}</dd>
            </div>
        </dl>
    </component>
</template>
