<script setup lang="ts">
/** Ads Hub — اقتراحات الإيقاف: running ads worth stopping with written reasons, then the log of every stop / run (spec §2.3). */
import MoneyCell from '@/components/ads/MoneyCell.vue';
import PlatformChip from '@/components/ads/PlatformChip.vue';
import StatusToggle from '@/components/ads/StatusToggle.vue';
import WhyList from '@/components/ads/WhyList.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import DataHealthBanner from '@/components/ads/DataHealthBanner.vue';
import RelativeTime from '@/components/crm/RelativeTime.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatRoas, reasonTexts, roasTone } from '@/lib/ads';
import type { AdsActionsProps } from '@/types/ads';
import { Head } from '@inertiajs/vue3';
import { CircleCheck, ClipboardList } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<AdsActionsProps>();

const { t, locale } = useI18n();

/** The reasons as one sentence list, to start the confirm dialog's reason box with. */
const reasonText = (s: AdsActionsProps['suggestions'][number]) =>
    reasonTexts(s.reasons, locale.value, props.currency)
        .map((r) => r.text)
        .join('. ');

const statusLabel = (s: string | null) => (s === 'ACTIVE' ? t('ads.actions.status_active') : s === 'PAUSED' ? t('ads.actions.status_paused') : (s ?? '—'));

const breadcrumbs = computed(() => [
    { title: t('nav.ads'), href: '/ads' },
    { title: t('nav.ads_actions'), href: '/ads/actions' },
]);
</script>

<template>
    <Head :title="t('ads.actions.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-[1400px] space-y-6 p-3 md:p-6">
            <DataHealthBanner :data-health="data_health" :numbers-under-review="numbers_under_review" :clamped-to-history="clamped_to_history" />
            <PageHeader :title="t('ads.actions.title')" :description="t('ads.actions.hint')" />

            <p class="text-2xs text-muted-foreground" :title="t('ads.scope_note_tip')">{{ t('ads.scope_note') }}</p>

            <section class="space-y-2" aria-labelledby="suggestions-title">
                <div>
                    <h2 id="suggestions-title" class="text-sm font-bold">{{ t('ads.actions.suggestions') }}</h2>
                    <p class="text-xs text-muted-foreground">{{ t('ads.actions.suggestions_hint', { days }) }}</p>
                </div>
                <div class="scrollbar-thin relative overflow-x-auto rounded-lg bg-card shadow-card [contain:inline-size]">
                    <EmptyState v-if="!suggestions.length" :icon="CircleCheck" :title="t('ads.actions.suggestions_empty')" />
                    <table v-else class="w-full min-w-[820px] text-xs">
                        <caption class="sr-only">
                            {{
                                t('ads.actions.suggestions')
                            }}
                        </caption>
                        <thead class="border-b border-border/60 text-2xs font-semibold text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-3 py-2 text-start">{{ t('ads.table.creative') }}</th>
                                <th scope="col" class="px-2 py-2 text-start">{{ t('ads.table.platform') }}</th>
                                <th scope="col" class="px-2 py-2 text-end">{{ t('ads.kpi.spend_tax') }}</th>
                                <th scope="col" class="px-2 py-2 text-end">{{ t('ads.kpi.roas') }}</th>
                                <th scope="col" class="px-2 py-2 text-start">{{ t('ads.actions.reason') }}</th>
                                <th scope="col" class="px-2 py-2"><span class="sr-only">{{ t('ads.actions.stop') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in suggestions" :key="s.ad_id" class="border-t border-border/60 align-top first:border-t-0">
                                <th scope="row" class="px-3 py-2 text-start font-[inherit]">
                                    <p class="max-w-72 font-bold text-foreground" dir="auto">{{ s.name }}</p>
                                    <p class="max-w-72 truncate text-2xs font-normal text-muted-foreground" dir="auto">{{ s.account }}</p>
                                </th>
                                <td class="px-2 py-2"><PlatformChip :platform="s.platform" size="xs" /></td>
                                <td class="px-2 py-2 text-end"><MoneyCell :amount="s.spend" :with-tax="s.spend_tax" :currency="currency" /></td>
                                <td class="px-2 py-2 text-end">
                                    <StatusChip :label="formatRoas(s.roas, locale)" :tone="roasTone(s.roas)" />
                                </td>
                                <td class="min-w-64 max-w-md px-2 py-2"><WhyList :reasons="s.reasons" :currency="currency" /></td>
                                <td class="px-2 py-2 text-center">
                                    <StatusToggle
                                        :account-id="s.account_id"
                                        :account="s.account"
                                        :platform="s.platform"
                                        level="ad"
                                        :external-id="s.external_id"
                                        :name="s.name"
                                        status="ACTIVE"
                                        :reason="reasonText(s)"
                                        :disabled="!s.can_write"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="space-y-2" aria-labelledby="log-title">
                <h2 id="log-title" class="text-sm font-bold">{{ t('ads.actions.log') }}</h2>
                <div class="scrollbar-thin relative overflow-x-auto rounded-lg bg-card shadow-card [contain:inline-size]">
                    <EmptyState v-if="!log.length" :icon="ClipboardList" :title="t('ads.actions.log_empty')" />
                    <table v-else class="w-full min-w-[820px] text-xs">
                        <caption class="sr-only">
                            {{
                                t('ads.actions.log')
                            }}
                        </caption>
                        <thead class="border-b border-border/60 text-2xs font-semibold text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-3 py-2 text-start">{{ t('ads.actions.col_time') }}</th>
                                <th scope="col" class="px-2 py-2 text-start">{{ t('ads.actions.col_user') }}</th>
                                <th scope="col" class="px-2 py-2 text-start">{{ t('ads.actions.col_target') }}</th>
                                <th scope="col" class="px-2 py-2 text-start">{{ t('ads.actions.col_change') }}</th>
                                <th scope="col" class="px-2 py-2 text-start">{{ t('ads.actions.col_reason') }}</th>
                                <th scope="col" class="px-2 py-2 text-start">{{ t('ads.actions.col_result') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="a in log" :key="a.id" class="border-t border-border/60 align-top first:border-t-0">
                                <td class="whitespace-nowrap px-3 py-2"><RelativeTime :iso="a.at" mode="datetime" /></td>
                                <td class="px-2 py-2">{{ a.user ?? t('ads.actions.unknown_user') }}</td>
                                <td class="px-2 py-2">
                                    <p class="max-w-72 font-medium" dir="auto">{{ a.name }}</p>
                                    <p class="max-w-72 truncate text-2xs text-muted-foreground">
                                        {{ t(`ads.campaigns.level_${a.level}`) }} · <span dir="auto">{{ a.account }}</span>
                                    </p>
                                </td>
                                <td class="whitespace-nowrap px-2 py-2">{{ t('ads.actions.change', { from: statusLabel(a.from_status), to: statusLabel(a.to_status) }) }}</td>
                                <td class="max-w-72 px-2 py-2 text-muted-foreground" dir="auto">{{ a.reason ?? t('ads.actions.reason_none') }}</td>
                                <td class="px-2 py-2">
                                    <StatusChip
                                        :label="t(a.result === 'ok' ? 'ads.actions.result_ok' : a.result === 'pending' ? 'ads.actions.result_pending' : 'ads.actions.result_error')"
                                        :tone="a.result === 'ok' ? 'positive' : a.result === 'pending' ? 'neutral' : 'negative'"
                                    />
                                    <p v-if="a.error" class="mt-0.5 max-w-64 text-2xs text-destructive" dir="auto">{{ a.error }}</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
