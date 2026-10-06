<script setup lang="ts">
/**
 * 09:00 digest card (D14, spec 7.5, U 5.3) for /ads and /today: owner variant (yesterday's numbers, money leaks,
 * winners, stock, inbox, stale accounts, approvals, buyers table with «الأرقام غلط» emphasised) or buyer variant
 * (own numbers and list). /today hands the digest in (deferred prop); /ads fetches it on mount.
 */
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import SkeletonList from '@/components/crm/SkeletonList.vue';
import { Button } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { formatAdsMoney, formatRoas } from '@/lib/ads';
import { alertSentence } from '@/lib/adsAlerts';
import { formatCount } from '@/lib/format';
import type { DigestBuyerRow, DigestData } from '@/types/ads';
import { Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const props = withDefaults(defineProps<{ digest?: DigestData | null }>(), { digest: undefined });

const { t, locale } = useI18n();
const api = useApi();
const fetched = ref<DigestData | null>(null);
const loading = ref(props.digest === undefined);
const error = ref<string | null>(null);
const data = computed(() => props.digest ?? fetched.value);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        fetched.value = (await api.get<DigestData>('/ads/alerts/digest', { silent: true })).data;
    } catch (e) {
        error.value = apiErrorMessage(e, t('ads.digest.error'));
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    if (props.digest === undefined) void load();
});

const money = (v: number | null | undefined) => formatAdsMoney(v, locale.value, data.value?.currency ?? 'EGP');
const owner = computed(() => data.value?.variant === 'owner');
const buyerColumns = computed<Column[]>(() => [
    { key: 'name', label: t('ads.digest.cols.buyer'), primary: true },
    { key: 'open', label: t('ads.digest.cols.open'), numeric: true },
    { key: 'acted', label: t('ads.digest.cols.acted'), numeric: true },
    { key: 'dismissed', label: t('ads.digest.cols.dismissed'), numeric: true },
    { key: 'dismissed_wrong_numbers', label: t('ads.digest.cols.wrong_numbers'), numeric: true },
    { key: 'silent_spend', label: t('ads.digest.cols.silent'), numeric: true },
]);
const buyerRows = computed<(DigestBuyerRow & { id: number })[]>(() => (data.value?.buyers ?? []).map((b) => ({ ...b, id: b.buyer_id })));
</script>

<template>
    <section class="space-y-3 rounded-lg bg-card p-4 shadow-card" aria-labelledby="digest-title" data-test="digest">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="digest-title" class="text-sm font-semibold">{{ t('ads.digest.title') }}</h2>
            <span
                v-if="data?.shadow"
                class="rounded-full bg-amber-100 px-2 py-0.5 text-2xs text-amber-900 dark:bg-amber-900 dark:text-amber-100"
                data-test="shadow-chip"
            >
                {{ t('ads.digest.shadow') }}
            </span>
        </header>

        <SkeletonList v-if="loading" variant="tiles" :count="4" />

        <div v-else-if="error" class="flex flex-wrap items-center gap-2 text-xs text-destructive" role="alert">
            <span>{{ error }}</span>
            <Button size="sm" variant="outline" data-test="retry" :loading="loading" @click="load">{{ t('ads.digest.retry') }}</Button>
        </div>

        <template v-else-if="data">
            <dl class="grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
                <div>
                    <dt class="text-muted-foreground">{{ t('ads.digest.spend') }}</dt>
                    <dd class="text-base font-bold tabular-nums">{{ money(data.yesterday.spend_tax) }}</dd>
                    <dd v-if="data.yesterday.usual_spend !== null" class="text-2xs text-muted-foreground" data-test="usual">
                        {{ t('ads.digest.usual', { money: money(data.yesterday.usual_spend) }) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('ads.digest.orders') }}</dt>
                    <dd class="text-base font-bold tabular-nums">{{ formatCount(data.yesterday.orders, locale) }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('ads.digest.roas') }}</dt>
                    <dd
                        class="text-base font-bold tabular-nums"
                        :class="data.yesterday.roas !== null && data.yesterday.roas < data.yesterday.floor ? 'text-destructive' : ''"
                        data-test="roas"
                    >
                        {{ formatRoas(data.yesterday.roas, locale) }}
                    </dd>
                    <dd class="text-2xs text-muted-foreground">
                        {{ t('ads.digest.meta_roas', { roas: formatRoas(data.yesterday.meta_roas, locale) }) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('ads.digest.vs_floor', { floor: formatRoas(data.yesterday.floor, locale) }) }}</dt>
                    <dd v-if="data.yesterday.floor_default" class="text-2xs text-muted-foreground" data-test="floor-default">
                        {{ t('ads.digest.floor_default') }}
                    </dd>
                </div>
            </dl>

            <p class="text-sm">
                <Link href="/ads/decisions" class="font-semibold text-primary hover:underline" data-test="open-decisions">
                    {{ t('ads.digest.open', { count: formatCount(data.open.count, locale), money: money(data.open.money) }) }}
                </Link>
            </p>

            <div v-if="data.top.length" class="space-y-1">
                <h3 class="text-xs font-semibold text-muted-foreground">{{ owner ? t('ads.digest.leaks') : t('ads.digest.my_list') }}</h3>
                <ol class="space-y-1.5 text-xs">
                    <li v-for="item in data.top" :key="item.alert_id" class="leading-relaxed" data-test="top-item">
                        <bdi class="font-semibold">{{ item.ad ?? item.account }}</bdi>
                        <span v-if="item.buyer" class="text-muted-foreground">
                            · <bdi>{{ item.buyer }}</bdi></span
                        >
                        — {{ alertSentence(item, locale) }}
                        <span class="text-muted-foreground"> · {{ t('ads.digest.age', { n: item.age_days }) }}</span>
                    </li>
                </ol>
            </div>
            <p v-else class="text-xs text-muted-foreground">{{ t('ads.digest.nothing') }}</p>

            <ul class="space-y-1 text-xs">
                <li v-if="data.winners.length">
                    {{ t('ads.digest.winners') }}: <bdi>{{ data.winners.map((w) => w.ad).join('، ') }}</bdi>
                </li>
                <li v-if="data.stock.ads > 0">
                    <Link href="/ads/decisions" class="text-primary hover:underline">{{ t('ads.digest.stock', { ads: data.stock.ads }) }}</Link>
                </li>
                <li v-if="data.inbox">
                    <Link href="/board" class="text-primary hover:underline">{{
                        t('ads.digest.inbox', { share: Number(data.inbox.share ?? 0) })
                    }}</Link>
                </li>
                <li v-if="data.stale_accounts > 0">
                    <Link href="/ads/sync" class="text-primary hover:underline">{{ t('ads.digest.stale', { n: data.stale_accounts }) }}</Link>
                </li>
                <li v-if="data.approvals > 0">
                    <Link href="/ads/approvals" class="text-primary hover:underline">{{ t('ads.digest.approvals', { n: data.approvals }) }}</Link>
                </li>
                <li v-if="data.review_waiting > 0">
                    <Link href="/ads/launches" class="text-primary hover:underline">{{
                        t('ads.digest.review_waiting', { n: data.review_waiting })
                    }}</Link>
                </li>
                <li v-if="data.stopped_yesterday > 0">{{ t('ads.digest.stopped', { n: data.stopped_yesterday }) }}</li>
            </ul>

            <div v-if="owner && buyerRows.length" data-test="buyers">
                <DataTable table-id="ads-digest-buyers" :columns="buyerColumns" :rows="buyerRows" :caption="t('ads.digest.buyers')" mobile="scroll">
                    <template #cell-name="{ row }"
                        ><bdi>{{ row.name }}</bdi></template
                    >
                    <template #cell-dismissed_wrong_numbers="{ row }">
                        <span
                            :class="row.dismissed_wrong_numbers > 0 ? 'font-bold text-destructive' : ''"
                            :data-test="`wrong-numbers-${row.buyer_id}`"
                        >
                            {{ formatCount(row.dismissed_wrong_numbers, locale) }}
                        </span>
                    </template>
                    <template #cell-silent_spend="{ row }">{{ money(row.silent_spend) }}</template>
                </DataTable>
            </div>
        </template>
    </section>
</template>
