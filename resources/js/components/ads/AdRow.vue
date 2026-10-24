<script setup lang="ts">
/** One ad, one column (U 3.1). Every ad table renders its cells through this component; every number opens the drawer. */
import AdStatusButton from '@/components/ads/AdStatusButton.vue';
import CreativeThumb from '@/components/ads/CreativeThumb.vue';
import HealthBadge from '@/components/ads/HealthBadge.vue';
import PlatformChip from '@/components/ads/PlatformChip.vue';
import Sparkline from '@/components/ads/Sparkline.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { adsManagerUrl, formatAdsMoney, formatRoas, isAdActive } from '@/lib/ads';
import type { AdPart } from '@/lib/adsColumns';
import { formatCount } from '@/lib/format';
import type { AdRowData } from '@/types/ads';
import { Link } from '@inertiajs/vue3';
import { MoreHorizontal } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ row: AdRowData; part: AdPart; currency?: string; thumb?: boolean; dataAt?: string | null }>(), {
    currency: 'EGP',
    thumb: true,
    dataAt: null,
});
const emit = defineEmits<{ open: [id: number]; done: [status: 'active' | 'paused'] }>();
const { t, locale } = useI18n();
const toast = useToast();
/** Money on a row is in its own account currency. */
const cur = computed(() => props.row.currency || props.currency);
const money = (v: number | null) => formatAdsMoney(v, locale.value, cur.value);
const n = (v: number) => formatCount(v, locale.value);
const active = computed(() => isAdActive(props.row));
const adsManager = computed(() => adsManagerUrl(props.row));
const result = computed(() =>
    props.row.objective === 'messages'
        ? t('ads.control.row.chats_orders', { chats: n(props.row.conversations), orders: n(props.row.real_orders) })
        : props.row.objective === 'traffic'
          ? t('ads.control.row.clicks', { n: n(props.row.clicks) })
          : t('ads.control.row.purchases_orders', { purchases: n(props.row.purchases), orders: n(props.row.real_orders) }),
);

async function copyId(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.row.external_id);
        toast.push(t('ads.control.row.copied'), 'success');
    } catch {
        /* clipboard blocked: nothing to do */
    }
}
</script>

<template>
    <div v-if="part === 'creative'" class="flex min-w-0 items-center gap-3">
        <button v-if="thumb" type="button" class="h-[52px] w-[42px] shrink-0 overflow-hidden rounded-md" :aria-label="t('ads.control.row.preview')" @click="emit('open', row.id)">
            <CreativeThumb :ad="row" size="fill" square :show-pills="false" class="!aspect-auto h-full" />
        </button>
        <div class="min-w-0 space-y-0.5">
            <button type="button" class="block max-w-[22rem] truncate text-start text-sm font-semibold text-foreground hover:underline" dir="auto" :title="row.name" @click="emit('open', row.id)">
                {{ row.name }}
            </button>
            <p class="flex min-w-0 items-center gap-1.5 truncate text-xs text-fg-2">
                <PlatformChip :platform="row.platform" size="xs" icon-only />
                <span dir="auto" class="truncate">{{ row.account }}</span><template v-if="row.buyer"> · <span dir="auto">{{ row.buyer }}</span></template> ·
                {{ t(`ads.control.objective.${row.objective}`) }}
            </p>
            <div v-if="row.health.length" class="flex flex-wrap gap-1"><HealthBadge v-for="h in row.health.slice(0, 2)" :key="h" :kind="h" /></div>
        </div>
    </div>

    <button v-else-if="part === 'spend'" type="button" class="block w-full text-end tabular-nums hover:underline" @click="emit('open', row.id)">
        <span class="block text-sm font-semibold">{{ money(row.spend_tax) }}</span>
        <span class="block text-xs text-fg-3">{{ t('ads.control.row.today', { amount: money(row.spend_today) }) }}</span>
    </button>

    <button v-else-if="part === 'result'" type="button" class="block w-full text-end text-xs tabular-nums hover:underline" @click="emit('open', row.id)">{{ result }}</button>

    <button v-else-if="part === 'cac'" type="button" data-test="cac" class="block w-full text-end text-sm font-semibold tabular-nums hover:underline" @click="emit('open', row.id)">
        {{ money(row.cac ?? null) }}
    </button>

    <button v-else-if="part === 'return'" type="button" class="block w-full text-end tabular-nums hover:underline" @click="emit('open', row.id)">
        <span data-test="real-roas" class="block text-sm font-semibold">
            <span class="sr-only">{{ t('ads.control.row.real') }} </span>{{ formatRoas(row.real_roas, locale) }}
        </span>
        <span data-test="meta-roas" class="block text-xs text-fg-3">
            {{ t('ads.control.row.meta') }} {{ row.objective === 'messages' ? '—' : formatRoas(row.roas, locale) }}
        </span>
    </button>

    <Sparkline v-else-if="part === 'trend'" :points="row.series" :currency="cur" />

    <div v-else class="flex items-center justify-end gap-1.5">
        <StatusChip :label="active ? t('ads.control.row.running') : t('ads.control.row.stopped')" :tone="active ? 'ok' : 'neutral'" size="sm" dot class="whitespace-nowrap" />
        <AdStatusButton
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
            :quiet="!row.health.some((h) => h === 'losing' || h === 'out_of_stock')"
            @done="emit('done', $event)"
        />
        <button type="button" data-test="why" class="h-11 whitespace-nowrap rounded-md px-3 text-xs font-semibold text-brand-ink hover:bg-brand-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:h-[30px] md:px-2.5" @click="emit('open', row.id)">
            {{ t('ads.control.row.why') }}
        </button>
        <DropdownMenu>
            <DropdownMenuTrigger class="inline-flex size-11 items-center justify-center rounded-md text-fg-2 hover:bg-sunken hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:size-[30px]" :aria-label="t('ads.control.row.more')">
                <MoreHorizontal class="size-4" aria-hidden="true" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem v-if="adsManager" as-child>
                    <a :href="adsManager" target="_blank" rel="noopener noreferrer">{{ t('ads.control.row.open_ads_manager') }}</a>
                </DropdownMenuItem>
                <DropdownMenuItem @select="copyId">{{ t('ads.control.row.copy_id') }}</DropdownMenuItem>
                <DropdownMenuItem as-child>
                    <Link :href="`/ads/explorer?view=tree&status=all&accounts=${row.account_id}`">{{ t('ads.control.row.go_campaign') }}</Link>
                </DropdownMenuItem>
                <DropdownMenuItem @select="emit('open', row.id)">{{ t('ads.control.row.history') }}</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
