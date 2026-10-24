<script setup lang="ts">
/**
 * One drawer for every ad list (U 3.4): preview, numbers + sparkline, ليه؟, open decisions, write history, actions.
 * Inline-end side (left in RTL), full width on phones. The chat funnel (S3) is fetched once per open, silently, from
 * GET /ads/chat-funnel for the page range; a host may pass `funnel` (or the `funnel` slot) instead.
 */
import AdStatusButton from '@/components/ads/AdStatusButton.vue';
import ChatFunnelBlock from '@/components/ads/ChatFunnelBlock.vue';
import CreativeThumb from '@/components/ads/CreativeThumb.vue';
import PlatformChip from '@/components/ads/PlatformChip.vue';
import HealthBadge from '@/components/ads/HealthBadge.vue';
import Sparkline from '@/components/ads/Sparkline.vue';
import WhyList from '@/components/ads/WhyList.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { useAdFunnel } from '@/composables/useAdFunnel';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { adsManagerUrl, allowedPreviewUrl, formatAdsMoney, formatRoas, previewSrcFromHtml, writeChangeKey } from '@/lib/ads';
import { formatCount, formatDateTime } from '@/lib/format';
import type { AdDrawerData, AdsFilters, ChatFunnel } from '@/types/ads';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        adId: number | null;
        filters: AdsFilters;
        currency?: string;
        dataAt?: string | null;
        funnel?: ChatFunnel | null;
        /** Page props to refresh after a Stop / Run from the drawer (the list behind it), e.g. ['result']. */
        reloadOnly?: string[];
    }>(),
    { currency: 'EGP', dataAt: null, funnel: null, reloadOnly: () => [] },
);
const emit = defineEmits<{ close: [] }>();
const { t, locale, dir } = useI18n();
const api = useApi();
const data = ref<AdDrawerData | null>(null);
const error = ref<string | null>(null);
let seq = 0;

/** The page's range only: the ad id decides the scope (the server checks it), so a narrowed list never hides a deep-linked ad. */
const rangeQuery = () => `?${new URLSearchParams({ from: props.filters.from, to: props.filters.to }).toString()}`;

async function load(id: number): Promise<void> {
    const mine = ++seq;
    error.value = null;
    try {
        const res = await api.get<AdDrawerData>(`/ads/ad/${id}${rangeQuery()}`);
        if (mine === seq) data.value = res.data;
    } catch (e) {
        if (mine === seq) error.value = apiErrorMessage(e, t('ads.control.drawer.load_failed'));
    }
}
watch(
    () => props.adId,
    (id) => {
        data.value = null;
        if (id) void load(id);
    },
    { immediate: true },
);

const ad = computed(() => data.value?.ad ?? null);
const cur = computed(() => ad.value?.currency || props.currency);
/** Fetched here unless the host passes one; once per open (not again after a Stop / Run). */
const own = useAdFunnel(
    () => (props.funnel === null ? props.adId : null),
    () => ({ from: props.filters.from, to: props.filters.to }),
);
const funnel = computed(() => props.funnel ?? own.funnel.value ?? data.value?.funnel ?? null);
const previewSrc = computed(() => allowedPreviewUrl(previewSrcFromHtml(ad.value?.preview_html ?? null)));
const adsManager = computed(() => (ad.value ? adsManagerUrl(ad.value) : null));
const money = (v: number | null) => formatAdsMoney(v, locale.value, cur.value);
/** The drawer reloads its own ad; the list behind it reloads only the keys the page named. */
function afterWrite(): void {
    if (props.adId) void load(props.adId);
    if (props.reloadOnly.length) router.reload({ only: props.reloadOnly });
}
const resultTone = (r: 'ok' | 'error' | 'pending') => (r === 'ok' ? 'positive' : r === 'pending' ? 'info' : 'negative');
</script>

<template>
    <Sheet :open="adId !== null" @update:open="(o: boolean) => !o && emit('close')">
        <SheetContent :side="dir === 'rtl' ? 'left' : 'right'" class="flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-[480px]">
            <div class="space-y-1 border-b border-line px-5 pb-3.5 pt-5 pe-12">
                <SheetTitle class="truncate text-start text-lg font-bold"><bdi>{{ ad?.name ?? t('ads.control.drawer.title') }}</bdi></SheetTitle>
                <SheetDescription class="flex min-w-0 items-center gap-1.5 text-[13px] text-fg-2">
                    <template v-if="ad"><PlatformChip :platform="ad.platform" size="xs" icon-only /><span dir="auto" class="truncate">{{ ad.account }}</span> · {{ t(`ads.control.objective.${ad.objective}`) }}</template>
                    <template v-else>{{ t('ads.control.drawer.title') }}</template>
                </SheetDescription>
            </div>

            <div v-if="error" class="m-5 space-y-2 rounded-lg bg-danger-soft p-5 text-center text-[13.5px] text-danger" role="alert">
                <p>{{ error }}</p>
                <button type="button" class="h-11 rounded-md bg-card px-4 font-semibold text-foreground hover:bg-surface-2" @click="adId && load(adId)">{{ t('ads.control.drawer.retry') }}</button>
            </div>
            <div v-else-if="!ad" class="space-y-3 p-5" aria-busy="true">
                <div class="mx-auto aspect-[4/5] w-full max-w-[320px] animate-pulse rounded-lg bg-sunken" />
                <div class="h-16 w-full animate-pulse rounded-lg bg-sunken" />
                <div class="h-4 w-2/3 animate-pulse rounded bg-sunken" />
            </div>

            <template v-else>
                <div class="flex-1 space-y-5 p-5">
                    <section :aria-label="t('ads.control.row.preview')">
                        <iframe
                            v-if="previewSrc"
                            :src="previewSrc"
                            :title="t('ads.control.row.preview')"
                            class="mx-auto aspect-[4/5] w-full max-w-[360px] rounded-lg bg-sunken"
                            loading="lazy"
                            sandbox="allow-scripts allow-same-origin allow-popups"
                        />
                        <div v-else class="mx-auto max-w-[320px] overflow-hidden rounded-lg"><CreativeThumb :ad="ad" size="fill" square :show-pills="false" class="!aspect-[4/5]" /></div>
                        <div v-if="ad.health.length" class="mt-2 flex flex-wrap gap-1"><HealthBadge v-for="h in ad.health" :key="h" :kind="h" /></div>
                    </section>

                    <section class="space-y-2">
                        <h3 class="text-[13px] font-semibold text-fg-2">{{ t('ads.control.drawer.numbers') }}</h3>
                        <dl class="grid grid-cols-2 overflow-hidden rounded-lg border border-line tabular-nums [&>div:nth-child(odd)]:border-e [&>div]:border-b [&>div]:border-line [&>div]:px-3 [&>div]:py-2.5">
                            <div>
                                <dt class="text-xs text-fg-3">{{ t('ads.control.col.spend') }}</dt>
                                <dd class="text-base font-bold">{{ money(ad.spend_tax) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-fg-3">{{ t('ads.control.write.today_spend') }}</dt>
                                <dd class="text-base font-bold">{{ money(ad.spend_today) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-fg-3">{{ t('ads.control.today.real_roas') }}</dt>
                                <dd class="text-base font-bold">{{ formatRoas(ad.real_roas, locale) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-fg-3">{{ t('ads.control.today.meta_roas') }}</dt>
                                <dd class="text-base font-semibold text-fg-2">{{ ad.objective === 'messages' ? '—' : formatRoas(ad.roas, locale) }}</dd>
                            </div>
                            <div class="col-span-2 !border-b-0 !border-e-0 text-[13px]">
                                <dt class="text-xs text-fg-3">{{ t('ads.control.col.result') }}</dt>
                                <dd v-if="ad.objective === 'messages'">
                                    {{ t('ads.control.row.chats_orders', { chats: formatCount(ad.conversations, locale), orders: formatCount(ad.real_orders, locale) }) }}
                                </dd>
                                <dd v-else-if="ad.objective === 'traffic'">{{ t('ads.control.row.clicks', { n: formatCount(ad.clicks, locale) }) }}</dd>
                                <dd v-else>{{ t('ads.control.row.purchases_orders', { purchases: formatCount(ad.purchases, locale), orders: formatCount(ad.real_orders, locale) }) }}</dd>
                                <dd data-test="drawer-cac" class="mt-1 text-xs text-fg-2">{{ t('ads.library_r3.drawer_cac') }}: <span class="font-semibold text-foreground">{{ money(ad.cac ?? null) }}</span></dd>
                            </div>
                        </dl>
                        <Sparkline :points="ad.series" :currency="cur" :width="320" :height="48" />
                    </section>

                    <section class="space-y-1">
                        <h3 class="text-[13px] font-semibold text-fg-2">{{ t('ads.control.drawer.why') }}</h3>
                        <WhyList v-if="data?.reasons.length" :reasons="data.reasons" :currency="cur" />
                        <p v-else class="text-[13px] text-fg-3">{{ t('ads.control.drawer.no_reasons') }}</p>
                    </section>

                    <section class="space-y-1">
                        <h3 class="text-[13px] font-semibold text-fg-2">{{ t('ads.control.drawer.decisions') }}</h3>
                        <ul v-if="data?.decisions.length" class="space-y-2">
                            <li v-for="(d, i) in data.decisions" :key="i" class="rounded-md bg-danger-soft/60 p-2.5">
                                <p class="text-[13px] font-semibold text-danger">{{ t('ads.control.drawer.stop_suggestion') }}</p>
                                <WhyList :reasons="d.reasons" :currency="cur" />
                            </li>
                        </ul>
                        <p v-else class="text-[13px] text-fg-3">{{ t('ads.control.drawer.no_decisions') }}</p>
                    </section>

                    <section data-test="funnel" class="space-y-1">
                        <h3 class="text-[13px] font-semibold text-fg-2" :title="t('ads.funnel.multi_touch')">{{ t('ads.control.drawer.funnel') }}</h3>
                        <slot name="funnel" :funnel="funnel">
                            <ChatFunnelBlock :funnel="funnel" :loading="own.loading.value" :error="own.error.value" :heading="false" @retry="own.retry" />
                        </slot>
                    </section>

                    <section class="space-y-1">
                        <h3 class="text-[13px] font-semibold text-fg-2">{{ t('ads.control.drawer.history') }}</h3>
                        <ul v-if="data?.history.length" class="divide-y divide-line text-[13px]">
                            <li v-for="h in data.history" :key="h.id" class="flex flex-wrap items-center justify-between gap-2 py-1.5">
                                <span>{{ t(writeChangeKey(h.to_status)) }} · <bdi>{{ h.user ?? '—' }}</bdi></span>
                                <span class="inline-flex items-center gap-2 text-xs text-fg-3">
                                    <span class="tabular-nums">{{ h.at ? formatDateTime(h.at, locale) : '—' }}</span>
                                    <StatusChip :label="t(`ads.control.decisions.result_${h.result}`)" :tone="resultTone(h.result)" size="sm" />
                                </span>
                            </li>
                        </ul>
                        <p v-else class="text-[13px] text-fg-3">{{ t('ads.control.drawer.no_history') }}</p>
                    </section>
                </div>

                <div class="sticky bottom-0 flex flex-wrap items-center gap-2 border-t border-line bg-card px-5 py-3.5 pb-[max(0.875rem,env(safe-area-inset-bottom))]">
                    <AdStatusButton
                        size="md"
                        :account-id="ad.account_id"
                        :account="ad.account"
                        :platform="ad.platform"
                        level="ad"
                        :external-id="ad.external_id"
                        :name="ad.name"
                        :status="ad.status"
                        :can-write="ad.can_write"
                        :spend-today="ad.spend_today"
                        :data-at="dataAt"
                        :currency="cur"
                        :parent-paused="ad.parent_paused"
                        no-reload
                        @done="afterWrite"
                    />
                    <a v-if="adsManager" :href="adsManager" target="_blank" rel="noopener noreferrer" class="ms-auto inline-flex h-11 items-center gap-1 rounded-md px-3 text-[13px] font-semibold text-brand-ink hover:bg-brand-soft md:h-9">
                        {{ t('ads.control.row.open_ads_manager') }}
                    </a>
                </div>
            </template>
        </SheetContent>
    </Sheet>
</template>
