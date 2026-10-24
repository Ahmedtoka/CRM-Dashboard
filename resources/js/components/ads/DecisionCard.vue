<script setup lang="ts">
/**
 * One «قرار اليوم» row (round 3): the creative, the kind chip (stock risk / cut / fix creative / scale / keep), the
 * evidence line (spend · results · ROAS · trend · days), the written reasons, and «نفّذ» — the exact change the write
 * pipeline will send (Stop, or the daily budget ±%), with the confirm dialog that repeats it. When CRM_ADS_EXECUTE is off
 * the button says it is a dry run. Google has no writer: no button.
 */
import CreativeThumb from '@/components/ads/CreativeThumb.vue';
import PlatformChip from '@/components/ads/PlatformChip.vue';
import DecisionRow from '@/components/ads/ui/DecisionRow.vue';
import WhyList from '@/components/ads/WhyList.vue';
import WriteActionDialog from '@/components/ads/WriteActionDialog.vue';
import { Button } from '@/components/ui/button';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { formatAdsMoney, formatRoas } from '@/lib/ads';
import { ADS_META, ADS_TONE } from '@/lib/adsUi';
import { formatCount } from '@/lib/format';
import type { AdsAccess, DailyDecision } from '@/types/ads';
import { router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Pause, Scissors, Sparkles, TrendingUp } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = withDefaults(defineProps<{ decision: DailyDecision; currency?: string; execute?: boolean; dataAt?: string | null; noReload?: boolean }>(), {
    currency: 'EGP',
    execute: true,
    dataAt: null,
    noReload: false,
});
const emit = defineEmits<{ openAd: [id: number]; done: [decision: DailyDecision] }>();
const { t, locale } = useI18n();
const page = usePage();
const open = ref(false);

const d = computed(() => props.decision);
const allowed = computed(() => ((page.props.ads ?? null) as AdsAccess | null)?.canWrite === true && d.value.can_write && d.value.ad.platform !== 'google');
const action = computed(() => d.value.action);
const tone = computed(() => ({ stock_risk: 'negative', cut: 'negative', fix_creative: 'warning', scale: 'positive', keep: 'neutral' })[d.value.kind] as 'negative' | 'warning' | 'positive' | 'neutral');
const icon = computed(() => ({ stock_risk: AlertTriangle, cut: Scissors, fix_creative: Sparkles, scale: TrendingUp, keep: Pause })[d.value.kind]);
const money = (v: number | null | undefined) => (v === null || v === undefined ? '—' : formatAdsMoney(v, locale.value, props.currency));
/** The one evidence sentence: spend · results · ROAS · trend · days. */
const evidence = computed(() => {
    const e = d.value.evidence;
    const parts = [
        t('ads.control.daily.ev_spend', { amount: money(e.spend) }),
        t('ads.control.daily.ev_results', { n: formatCount(e.results, locale.value), orders: formatCount(e.real_orders, locale.value) }),
        e.roas !== null ? t('ads.control.daily.ev_roas', { roas: formatRoas(e.roas, locale.value) }) : null,
        e.cac !== null && e.cac !== undefined ? t('ads.control.daily.ev_cac', { amount: money(e.cac) }) : null,
        t(`ads.control.daily.trend_${e.trend?.dir ?? 'flat'}`),
        t('ads.control.daily.ev_days', { n: formatCount(e.days, locale.value) }),
    ];
    return parts.filter(Boolean).join(' · ');
});
const executeLabel = computed(() => {
    if (!action.value) return '';
    if (action.value.type === 'set_budget') return t('ads.control.daily.execute_budget', { pct: `${(action.value.pct ?? 0) > 0 ? '+' : ''}${action.value.pct ?? 0}%` });
    return t(action.value.to === 'paused' ? 'ads.control.daily.execute_stop' : 'ads.control.daily.execute_run');
});
const thumb = computed(() => ({ name: d.value.ad.name, thumbnail_url: d.value.ad.thumbnail_url, image_url: null, type: null, effective_status: d.value.ad.status, created_time: null }));

function done(): void {
    emit('done', d.value);
    if (!props.noReload) router.reload();
}
</script>

<template>
    <DecisionRow :data-test="`decision-${decision.kind}`">
        <template #thumb>
            <button type="button" class="block size-full" :aria-label="t('ads.control.row.preview')" @click="emit('openAd', decision.ad.id)">
                <CreativeThumb :ad="thumb" size="fill" square :show-pills="false" class="!aspect-auto h-full" />
            </button>
        </template>
        <h3 class="flex min-w-0 flex-wrap items-center gap-2">
            <StatusChip :label="t(`ads.control.daily.kind_${decision.kind}`)" :tone="tone" size="sm" :icon="icon" />
            <button type="button" class="min-w-0 truncate text-start text-sm font-semibold hover:underline" dir="auto" @click="emit('openAd', decision.ad.id)">
                {{ decision.ad.name }}
            </button>
            <PlatformChip :platform="String(decision.ad.platform)" size="xs" icon-only />
        </h3>
        <p :class="ADS_META">
            <bdi>{{ decision.ad.account }}</bdi><template v-if="decision.ad.campaign"> · <bdi>{{ decision.ad.campaign }}</bdi></template>
        </p>
        <p class="text-[12.5px] text-fg-2" data-test="evidence">{{ evidence }}</p>
        <p v-if="decision.stock" class="text-[12.5px] font-medium text-danger" data-test="stock">
            {{ t(decision.stock.state === 'out' ? 'ads.control.daily.stock_out' : 'ads.control.daily.stock_low', { product: decision.stock.product ?? decision.stock.material, n: formatCount(decision.stock.inventory, locale), threshold: formatCount(decision.stock.threshold, locale) }) }}
        </p>
        <WhyList :reasons="decision.reasons" :currency="currency" class="pt-1" />
        <p v-if="decision.kind === 'keep'" class="pt-1 text-2xs text-fg-3">{{ t('ads.control.daily.keep_note') }}</p>
        <template #actions>
            <Button variant="ghost" size="sm" class="h-11 md:h-[30px]" :class="ADS_TONE.quiet" @click="emit('openAd', decision.ad.id)">{{ t('ads.control.row.why') }}</Button>
            <template v-if="action && allowed">
                <button
                    type="button"
                    data-test="execute"
                    class="inline-flex h-11 shrink-0 items-center gap-1.5 rounded-md px-3 text-xs font-semibold transition-[filter] hover:brightness-95 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring md:h-[30px] md:px-2.5"
                    :class="action.type === 'set_status' && action.to === 'paused' ? 'bg-danger-soft text-danger' : 'bg-ok-soft text-ok'"
                    :title="execute ? undefined : t('ads.control.daily.dry_run_hint')"
                    @click.stop="open = true"
                >
                    {{ t('ads.control.daily.execute') }}: {{ executeLabel }}
                    <span v-if="!execute" class="rounded bg-card/70 px-1 text-2xs font-medium">{{ t('ads.control.daily.dry_run') }}</span>
                </button>
                <WriteActionDialog
                    v-model:open="open"
                    :account-id="decision.ad.account_id"
                    :account="decision.ad.account"
                    :platform="String(decision.ad.platform)"
                    :level="action.type === 'set_budget' ? 'ad' : 'ad'"
                    :external-id="decision.ad.external_id"
                    :name="decision.ad.name"
                    :type="action.type"
                    :to="action.type === 'set_status' ? action.to : null"
                    :pct="action.type === 'set_budget' ? action.pct : null"
                    :spend-today="decision.spend_today"
                    :data-at="dataAt"
                    :currency="currency"
                    :reason="t(`ads.control.daily.kind_${decision.kind}`)"
                    :parent-paused="decision.ad.parent_paused"
                    @done="done"
                />
            </template>
        </template>
    </DecisionRow>
</template>
