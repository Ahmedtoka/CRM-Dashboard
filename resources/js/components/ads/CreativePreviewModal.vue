<script setup lang="ts">
/**
 * Creative preview (Arena layout): dark preview pane with tabs at the bottom, details + stats on the side.
 * The row already on the page renders at once; the JSON detail (`/ads/creatives/{id}`, same range) adds the
 * platform preview markup. That markup is never rendered: only its first iframe src is taken, host-checked
 * (https, facebook.com / fb.com), and loaded as a cross-origin iframe. No srcdoc, no v-html.
 */
import MoneyCell from '@/components/ads/MoneyCell.vue';
import PlatformChip from '@/components/ads/PlatformChip.vue';
import TrendArrow from '@/components/ads/TrendArrow.vue';
import WhyList from '@/components/ads/WhyList.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import {
    adTypeKey,
    allowedPreviewUrl,
    fbPostEmbedUrl,
    formatAdsMoney,
    formatPct,
    formatQty,
    formatRoas,
    isAdActive,
    previewSrcFromHtml,
    rangeQueryString,
    roasTone,
    safeUrl,
} from '@/lib/ads';
import { formatCount } from '@/lib/format';
import type { AdReason, AdsFilters, CreativeDetail, CreativeRow } from '@/types/ads';
import { ExternalLink, Instagram, LoaderCircle } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        open: boolean;
        ad: CreativeRow | null;
        filters: AdsFilters;
        currency?: string;
        /** Winners page: the smoothed ROAS tile. */
        smoothedRoas?: number | null;
        /** Winners page: why the ad has its tier. */
        reasons?: AdReason[];
    }>(),
    { currency: 'EGP', smoothedRoas: null, reasons: () => [] },
);
const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const { t, locale } = useI18n();
const api = useApi();

const detail = ref<CreativeDetail | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
type Tab = 'meta' | 'post' | 'image' | 'video';
const tab = ref<Tab | null>(null);
let seq = 0;

async function load(id: number): Promise<void> {
    const mine = ++seq;
    loading.value = true;
    error.value = null;
    try {
        const { data } = await api.get<CreativeDetail>(`/ads/creatives/${id}${rangeQueryString(props.filters)}`);
        if (mine === seq) detail.value = data;
    } catch (e) {
        if (mine === seq) error.value = apiErrorMessage(e, t('ads.preview.load_failed'));
    } finally {
        if (mine === seq) loading.value = false;
    }
}

watch(
    () => [props.open, props.ad?.id] as const,
    ([open, id]) => {
        if (!open || !id) return;
        if (detail.value?.id !== id) detail.value = null;
        tab.value = null;
        void load(id);
    },
    { immediate: true },
);

/** The fetched detail when it is for this ad, else the row from the page. */
const row = computed<CreativeRow | CreativeDetail | null>(() => (detail.value && detail.value.id === props.ad?.id ? detail.value : props.ad));
const previewHtml = computed(() => (detail.value && detail.value.id === props.ad?.id ? detail.value.preview_html : null));
/** Meta preview frame: the iframe src inside the preview markup, else preview_url — both host-checked. */
const metaSrc = computed(() => previewSrcFromHtml(previewHtml.value) ?? allowedPreviewUrl(row.value?.preview_url));
const postUrl = computed(() => allowedPreviewUrl(fbPostEmbedUrl(row.value?.object_story_id)));
const imageUrl = computed(() => safeUrl(row.value?.image_url) ?? safeUrl(row.value?.thumbnail_url));
const videoUrl = computed(() => safeUrl(row.value?.video_url));
const permalink = computed(() => safeUrl(row.value?.permalink_url));
const igLink = computed(() => safeUrl(row.value?.instagram_permalink_url));

const tabs = computed<Tab[]>(() => {
    const out: Tab[] = [];
    if (metaSrc.value) out.push('meta');
    if (postUrl.value) out.push('post');
    if (imageUrl.value) out.push('image');
    if (videoUrl.value) out.push('video');
    return out;
});
const current = computed<Tab | null>(() => (tab.value && tabs.value.includes(tab.value) ? tab.value : (tabs.value[0] ?? null)));

const active = computed(() => (row.value ? isAdActive(row.value) : false));
const money = (v: number | null) => formatAdsMoney(v, locale.value, props.currency);

const tiles = computed(() => {
    const r = row.value;
    if (!r) return [];
    const list: { key: string; label: string; value: string; tone?: string }[] = [
        { key: 'roas', label: t('ads.kpi.roas'), value: formatRoas(r.roas, locale.value), tone: roasTone(r.roas) },
        { key: 'orders', label: t('ads.kpi.meta_orders'), value: formatQty(r.purchases, locale.value) },
        { key: 'impressions', label: t('ads.kpi.impressions'), value: formatCount(r.impressions, locale.value) },
        { key: 'clicks', label: t('ads.kpi.clicks'), value: formatCount(r.clicks, locale.value) },
        { key: 'ctr', label: t('ads.kpi.ctr'), value: formatPct(r.ctr, locale.value) },
    ];
    if (props.smoothedRoas !== null)
        list.push({ key: 'smoothed', label: t('ads.winners.smoothed_roas'), value: formatRoas(props.smoothedRoas, locale.value) });
    return list;
});

const toneText: Record<string, string> = {
    positive: 'text-emerald-700 dark:text-emerald-300',
    warning: 'text-amber-700 dark:text-amber-300',
    negative: 'text-destructive',
    neutral: 'text-foreground',
};

const SANDBOX = 'allow-scripts allow-same-origin allow-popups';
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-h-[92svh] gap-0 overflow-hidden p-0 sm:max-w-5xl">
            <div v-if="row" class="grid max-h-[92svh] grid-cols-1 overflow-y-auto md:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)] md:overflow-hidden">
                <!-- Preview pane: dark in both themes, like the platform's own preview. -->
                <section class="flex min-h-[420px] flex-col bg-zinc-950 text-zinc-100 md:max-h-[92svh]" :aria-label="t('ads.preview.pane')">
                    <div class="relative flex flex-1 items-center justify-center overflow-hidden p-3">
                        <LoaderCircle v-if="loading && !current" class="size-6 animate-spin text-zinc-400" aria-hidden="true" />
                        <iframe
                            v-else-if="current === 'meta' && metaSrc"
                            :key="metaSrc"
                            :src="metaSrc"
                            :sandbox="SANDBOX"
                            referrerpolicy="no-referrer"
                            :title="t('ads.preview.meta')"
                            class="h-[560px] max-h-full w-full max-w-[540px] rounded-md bg-white"
                        />
                        <iframe
                            v-else-if="current === 'post' && postUrl"
                            :src="postUrl"
                            :sandbox="SANDBOX"
                            :title="t('ads.preview.post')"
                            class="h-[560px] max-h-full w-full max-w-[520px] rounded-md bg-white"
                        />
                        <img
                            v-else-if="current === 'image' && imageUrl"
                            :src="imageUrl"
                            :alt="row.name"
                            referrerpolicy="no-referrer"
                            class="max-h-[560px] max-w-full rounded-md object-contain"
                        />
                        <video
                            v-else-if="current === 'video' && videoUrl"
                            :src="videoUrl"
                            :poster="imageUrl ?? undefined"
                            controls
                            preload="metadata"
                            class="max-h-[560px] max-w-full rounded-md"
                        />
                        <p v-else class="text-xs text-zinc-400">{{ t('ads.preview.none') }}</p>
                        <span
                            v-if="loading"
                            class="absolute end-3 top-3 inline-flex items-center gap-1 rounded-full bg-black/60 px-2 py-0.5 text-2xs text-zinc-300"
                        >
                            <LoaderCircle class="size-3 animate-spin" aria-hidden="true" />{{ t('common.loading') }}
                        </span>
                    </div>
                    <div v-if="tabs.length" class="flex shrink-0 border-t border-white/10" role="tablist" :aria-label="t('ads.preview.pane')">
                        <button
                            v-for="tb in tabs"
                            :key="tb"
                            type="button"
                            role="tab"
                            :aria-selected="current === tb"
                            class="h-10 flex-1 text-xs font-medium transition-colors"
                            :class="current === tb ? 'bg-white/10 text-white' : 'text-zinc-400 hover:text-white'"
                            @click="tab = tb"
                        >
                            {{ t(`ads.preview.${tb}`) }}
                        </button>
                    </div>
                </section>

                <!-- Details pane -->
                <section class="flex flex-col gap-3 p-5 md:max-h-[92svh] md:overflow-y-auto">
                    <div class="pe-6">
                        <DialogTitle class="text-base font-bold leading-snug" dir="auto">{{ row.name }}</DialogTitle>
                        <DialogDescription class="sr-only">{{ t('ads.preview.description') }}</DialogDescription>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <StatusChip :label="t(`ads.type.${adTypeKey(row.type)}`)" />
                        <PlatformChip :platform="row.platform" size="xs" />
                        <StatusChip :label="active ? t('ads.status.active') : t('ads.status.inactive')" :tone="active ? 'positive' : 'neutral'" dot />
                    </div>

                    <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-xs">
                        <dt class="text-muted-foreground">{{ t('ads.table.account') }}</dt>
                        <dd class="truncate" dir="auto">{{ row.account }}</dd>
                        <dt class="text-muted-foreground">{{ t('ads.table.campaign') }}</dt>
                        <dd class="truncate" dir="auto">{{ row.campaign ?? '—' }}</dd>
                        <dt class="text-muted-foreground">{{ t('ads.table.adset') }}</dt>
                        <dd class="truncate" dir="auto">{{ row.adset ?? '—' }}</dd>
                        <template v-if="row.buyer">
                            <dt class="text-muted-foreground">{{ t('ads.table.buyer') }}</dt>
                            <dd class="truncate">{{ row.buyer }}</dd>
                        </template>
                    </dl>

                    <p v-if="row.headline" class="text-sm font-semibold" dir="auto">«{{ row.headline }}»</p>
                    <p
                        v-if="row.body"
                        class="max-h-40 overflow-y-auto whitespace-pre-line rounded-md bg-muted px-3 py-2 text-xs leading-relaxed"
                        dir="auto"
                    >
                        {{ row.body }}
                    </p>

                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        <div class="rounded-md border border-border px-3 py-2">
                            <p class="text-2xs text-muted-foreground">{{ t('ads.kpi.spend') }}</p>
                            <MoneyCell :amount="row.spend" :with-tax="row.spend_tax" :currency="currency" align="start" />
                        </div>
                        <div v-for="tile in tiles" :key="tile.key" class="rounded-md border border-border px-3 py-2">
                            <p class="text-2xs text-muted-foreground">{{ tile.label }}</p>
                            <p class="text-sm font-bold tabular-nums" :class="toneText[tile.tone ?? 'neutral']">{{ tile.value }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <TrendArrow :trend="row.trend" />
                        <StatusChip v-if="row.fatigue.flag" :label="t('ads.fatigue.label')" tone="negative" />
                    </div>
                    <div v-if="reasons.length">
                        <p class="mb-1 text-2xs font-semibold text-foreground">{{ t('ads.reasons.title') }}</p>
                        <WhyList :reasons="reasons" :currency="currency" />
                    </div>

                    <p class="text-xs text-muted-foreground">
                        {{ t('ads.preview.platform_revenue', { amount: money(row.purchase_value), orders: formatQty(row.purchases, locale) }) }}
                    </p>
                    <p class="text-xs text-muted-foreground">{{ t('ads.preview.real_orders', { n: row.real_orders }) }}</p>

                    <p v-if="error" role="alert" class="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">{{ error }}</p>

                    <div class="mt-auto flex flex-wrap gap-2 pt-2">
                        <a
                            v-if="permalink"
                            :href="permalink"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex h-9 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground hover:bg-primary/90"
                        >
                            <ExternalLink class="size-3.5" aria-hidden="true" />{{ t('ads.preview.original_post') }}
                        </a>
                        <a
                            v-if="igLink"
                            :href="igLink"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex h-9 items-center gap-1.5 rounded-md border border-border px-3 text-xs font-medium hover:bg-muted"
                        >
                            <Instagram class="size-3.5" aria-hidden="true" />{{ t('ads.preview.instagram') }}
                        </a>
                    </div>
                </section>
            </div>
        </DialogContent>
    </Dialog>
</template>
