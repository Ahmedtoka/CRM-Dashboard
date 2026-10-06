<script setup lang="ts">
import PageHeader from '@/components/crm/PageHeader.vue';
import SkeletonList from '@/components/crm/SkeletonList.vue';
import DayToggle from '@/components/today/DayToggle.vue';
import TeamLine from '@/components/today/TeamLine.vue';
import TodayCard from '@/components/today/TodayCard.vue';
import UrgentStrip from '@/components/today/UrgentStrip.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount } from '@/lib/format';
import { adsRows, chatsRows, formatTodayDate, oldestStamp, ordersRows, whyRows } from '@/lib/today';
import type { SharedData } from '@/types';
import type { TeamRow, TodayCardsData, TodayMode, UrgentItem } from '@/types/today';
import { Deferred, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    mode: TodayMode;
    date: string;
    generated_at: string;
    /** The cards' own cache stamp (deferred with them): the page shows the older of the two. */
    cards_generated_at?: string;
    urgent: UrgentItem[] | null;
    cards?: TodayCardsData;
    team?: TeamRow[];
    /** S5 fills this with the 09:00 digest (DigestCard); null or absent until then, and the page renders nothing for it. */
    digest?: unknown | null;
}>();

const { t, locale } = useI18n();
const page = usePage<SharedData>();

const title = computed(
    () => `${props.mode === 'today' ? t('today.title') : t('today.title_yesterday')} · ${formatTodayDate(props.date, locale.value)}`,
);
const breadcrumbs = computed(() => [{ title: t('today.title'), href: '/today' }]);
const c = computed(() => props.cards);
const freshness = computed(() => oldestStamp([props.generated_at, props.cards_generated_at]));
</script>

<template>
    <Head :title="t('today.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader
                :title="title"
                :description="mode === 'today' ? t('today.description') : t('today.description_yesterday')"
                :freshness="freshness"
            >
                <DayToggle :mode="mode" />
            </PageHeader>

            <UrgentStrip v-if="urgent !== null" :items="urgent" />

            <!-- S5: <DigestCard v-if="digest" :digest="digest" /> -->
            <div v-if="digest" data-today-digest />

            <Deferred data="cards">
                <template #fallback>
                    <SkeletonList variant="cards" :count="4" :aria-label="t('today.loading')" />
                </template>
                <div v-if="c" class="grid gap-3 md:grid-cols-2 [&>*]:min-w-0">
                    <TodayCard :title="t('today.cards.chats')" :rows="chatsRows(c.chats, t, locale)" />
                    <TodayCard :title="t('today.cards.orders')" :rows="ordersRows(c.orders, t, locale)" />
                    <TodayCard
                        :title="t('today.cards.ads')"
                        :hint="mode === 'today' ? t('today.cards.ads_window_today') : t('today.cards.ads_window_yesterday')"
                        :rows="c.ads ? adsRows(c.ads, t, locale) : []"
                        :empty="t('today.cards.ads_none')"
                    >
                        <p v-if="c.ads?.currency === 'mixed'" class="mt-2 text-2xs text-muted-foreground">{{ t('today.cards.ads_mixed') }}</p>
                    </TodayCard>
                    <TodayCard
                        :title="t('today.cards.why')"
                        :hint="c.why.total > 0 ? t('today.why.total', { n: formatCount(c.why.total, locale) }) : t('today.cards.why_hint')"
                        :rows="whyRows(c.why, t, locale)"
                        :empty="t('today.cards.why_empty')"
                    />
                </div>
            </Deferred>

            <Deferred data="team">
                <template #fallback>
                    <SkeletonList variant="table" :count="5" />
                </template>
                <TeamLine v-if="team" :rows="team" :mode="mode" :can-see-board="page.props.canSeeBoard === true" />
            </Deferred>
        </div>
    </AppLayout>
</template>
