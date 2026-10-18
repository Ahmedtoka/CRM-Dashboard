<script setup lang="ts">
/**
 * «التجربة › جلسات الفريق» (design 2026-09-21 §4).
 *
 * Three readings of the same testing, in the order the owner asks for them: the
 * headline counts, one row per run, and the funnel that says where each flow loses
 * people. A run opens its own transcript, with a way through to the inbox.
 */
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import IconAction from '@/components/crm/IconAction.vue';
import InlineError from '@/components/crm/InlineError.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import SectionTabs from '@/components/crm/SectionTabs.vue';
import SkeletonList from '@/components/crm/SkeletonList.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import Ledger, { type LedgerFigure } from '@/components/today/Ledger.vue';
import { Button } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatShortDuration } from '@/lib/format';
import { sectionTabs } from '@/lib/sectionTabs';
import type { TeamTestFunnel, TeamTestLinkOption, TeamTestSessionRow, TeamTestTotals, TeamTestTranscriptLine } from '@/types/admin';
import { Head, router } from '@inertiajs/vue3';
import { Download, FlaskConical, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    links: TeamTestLinkOption[];
    linkId: number | null;
    sessions: TeamTestSessionRow[];
    totals: TeamTestTotals;
    funnels: TeamTestFunnel[];
}>();

const { t, locale } = useI18n();
const api = useApi();

const openSession = ref<TeamTestSessionRow | null>(null);
const transcript = ref<TeamTestTranscriptLine[]>([]);
const loading = ref(false);
const loadError = ref<string | null>(null);

const exportUrl = computed(() => `/bot/test-sessions/export${props.linkId ? `?link=${props.linkId}` : ''}`);

function pickLink(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    router.get('/bot/test-sessions', value ? { link: Number(value) } : {}, { preserveScroll: true });
}

async function show(row: TeamTestSessionRow): Promise<void> {
    openSession.value = row;
    transcript.value = [];
    loading.value = true;
    loadError.value = null;

    try {
        const { data } = await api.get(`/bot/test-sessions/${row.id}`);
        transcript.value = data.data.transcript;
    } catch (e) {
        loadError.value = apiErrorMessage(e, t('common.error'));
    } finally {
        loading.value = false;
    }
}

const stamp = (value: string | null): string =>
    value ? new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'short', timeStyle: 'short' }) : '—';

const duration = (seconds: number): string => formatShortDuration(seconds, locale.value);

function who(line: TeamTestTranscriptLine): string {
    if (line.sender === 'customer') return t('reports.team_test.you');
    if (line.sender === 'user') return line.author ?? t('reports.team_test.agent');
    return t('reports.team_test.bot');
}

const columns = computed<Column[]>(() => [
    { key: 'label', label: t('reports.team_test.tester'), primary: true },
    { key: 'link_label', label: t('settings.test_links.link'), dir: 'auto' },
    { key: 'started_at', label: t('reports.team_test.started') },
    { key: 'duration_seconds', label: t('reports.team_test.duration') },
    { key: 'messages_total', label: t('reports.team_test.messages'), align: 'center' },
    { key: 'device_family', label: t('reports.team_test.device') },
    { key: 'flows', label: t('reports.team_test.flows') },
    { key: 'last_step', label: t('reports.team_test.last_step') },
    { key: 'outcome', label: t('reports.team_test.outcome'), align: 'center' },
    { key: 'cases', label: t('reports.team_test.cases'), align: 'center' },
    { key: 'handovers', label: t('reports.team_test.handovers'), align: 'center' },
]);

/** The headline ledger: six figures in one panel (the testers ride under the sessions). */
const figures = computed<LedgerFigure[]>(() => {
    const x = props.totals;
    const n = (v: number) => formatCount(v, locale.value);
    return [
        {
            key: 'sessions',
            label: t('reports.team_test.totals.sessions'),
            value: n(x.sessions),
            note: t('reports.team_test.totals.testers_note', { count: n(x.testers) }),
        },
        { key: 'messages', label: t('reports.team_test.totals.messages'), value: n(x.messages) },
        { key: 'finished', label: t('reports.team_test.totals.finished'), value: n(x.finished) },
        { key: 'cases', label: t('reports.team_test.totals.cases'), value: n(x.cases) },
        { key: 'handovers', label: t('reports.team_test.totals.handovers'), value: n(x.handovers) },
        { key: 'avg_duration', label: t('reports.team_test.totals.avg_duration'), value: duration(x.avg_duration_seconds) },
    ];
});

const breadcrumbs = computed(() => [
    { title: t('nav.bot'), href: '/bot' },
    { title: t('nav.bot_testing'), href: '/bot/test-links' },
    { title: t('nav.bot_test_sessions'), href: '/bot/test-sessions' },
]);
const select =
    'h-10 rounded-md border border-line-strong bg-card px-2 text-13 focus-visible:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40';
</script>

<template>
    <Head :title="t('nav.bot_testing')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-4 p-3 md:p-6">
            <PageHeader
                :title="t('nav.bot_testing')"
                :description="t('settings.test_links.section_hint')"
                :breadcrumbs="[{ label: t('nav.bot'), href: '/bot' }, { label: t('nav.bot_testing') }]"
            >
                <select :value="props.linkId ?? ''" :class="select" :aria-label="t('reports.team_test.all_links')" @change="pickLink">
                    <option value="">{{ t('reports.team_test.all_links') }}</option>
                    <option v-for="link in props.links" :key="link.id" :value="link.id">{{ link.label }}</option>
                </select>
                <Button as-child variant="outline" class="gap-1.5">
                    <a :href="exportUrl"><Download class="size-4" aria-hidden="true" />{{ t('reports.team_test.export') }}</a>
                </Button>
            </PageHeader>
            <SectionTabs :tabs="sectionTabs.botTesting(t)" :label="t('nav.bot_testing')" />

            <Ledger :figures="figures" :label="t('reports.team_test.totals.label')" />

            <DataTable
                table-id="team-test"
                :columns="columns"
                :rows="props.sessions"
                :empty="t('reports.team_test.empty')"
                :empty-icon="FlaskConical"
                :caption="t('reports.team_test.title')"
                clickable
                @row-click="show"
            >
                <template #cell-label="{ row }"
                    ><span class="font-medium" dir="auto">{{ row.label }}</span></template
                >
                <template #cell-started_at="{ row }"
                    ><span class="tabular-nums text-fg-2">{{ stamp(row.started_at) }}</span></template
                >
                <template #cell-duration_seconds="{ row }"
                    ><span class="tabular-nums">{{ duration(row.duration_seconds) }}</span></template
                >
                <template #cell-messages_total="{ row }">
                    <span class="tabular-nums">{{ formatCount(row.messages_total, locale) }}</span>
                </template>
                <template #cell-flows="{ row }">
                    <span v-if="!row.flows.length" class="text-fg-3">—</span>
                    <span v-else class="flex flex-wrap gap-1">
                        <StatusChip v-for="flow in row.flows" :key="flow.key" size="sm" tone="outline" :label="flow.title" dir="auto" />
                    </span>
                </template>
                <template #cell-last_step="{ row }"
                    ><code class="text-xs" dir="ltr">{{ row.last_step ?? '—' }}</code></template
                >
                <template #cell-outcome="{ row }">
                    <StatusChip
                        size="sm"
                        :tone="row.finished ? 'ok' : 'warn'"
                        :label="row.finished ? t('reports.team_test.finished') : t('reports.team_test.dropped')"
                    />
                </template>
                <template #cell-cases="{ row }"
                    ><span class="tabular-nums">{{ formatCount(row.cases, locale) }}</span></template
                >
                <template #cell-handovers="{ row }"
                    ><span class="tabular-nums">{{ formatCount(row.handovers, locale) }}</span></template
                >
            </DataTable>

            <!-- The funnel: how many runs reached each step, in the flow's own order. -->
            <section v-if="props.funnels.length" class="space-y-3" aria-labelledby="team-test-funnel">
                <h2 id="team-test-funnel" class="text-base font-bold">{{ t('reports.team_test.funnel') }}</h2>

                <article v-for="funnel in props.funnels" :key="funnel.key" class="rounded-lg border border-line bg-card p-4">
                    <header class="mb-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <h3 class="text-sm font-semibold" dir="auto">{{ funnel.title }}</h3>
                        <p class="text-13 text-fg-2">
                            {{ t('reports.team_test.funnel_entered') }} <span class="tabular-nums">{{ formatCount(funnel.entered, locale) }}</span> ·
                            {{ t('reports.team_test.funnel_finished') }} <span class="tabular-nums">{{ formatCount(funnel.finished, locale) }}</span>
                        </p>
                    </header>

                    <ol class="space-y-1.5">
                        <li
                            v-for="step in funnel.steps"
                            :key="step.id"
                            class="grid grid-cols-[minmax(0,8rem)_1fr_auto] items-center gap-3 text-13 sm:grid-cols-[minmax(0,10rem)_1fr_auto]"
                        >
                            <code class="truncate text-xs text-fg-2" dir="ltr">{{ step.id }}</code>
                            <span class="h-2 rounded-full bg-sunken">
                                <span
                                    class="block h-2 rounded-full bg-chart-1"
                                    :style="{ width: `${funnel.entered > 0 ? Math.round((step.reached / funnel.entered) * 100) : 0}%` }"
                                />
                            </span>
                            <span class="tabular-nums">
                                {{ formatCount(step.reached, locale) }}
                                <span v-if="step.dropped" class="text-warn"
                                    >· {{ t('reports.team_test.funnel_dropped') }} {{ formatCount(step.dropped, locale) }}</span
                                >
                            </span>
                        </li>
                    </ol>

                    <p v-if="funnel.top_drop_offs.length" class="mt-3 flex flex-wrap items-center gap-2 text-xs text-fg-2">
                        <span>{{ t('reports.team_test.top_drop_offs') }}</span>
                        <code v-for="drop in funnel.top_drop_offs" :key="drop.id" class="rounded-sm bg-warn-soft px-1.5 py-0.5 text-warn" dir="ltr">
                            {{ drop.id }} ({{ drop.dropped }})
                        </code>
                    </p>
                </article>
            </section>
        </div>

        <!-- The transcript of one run, read straight down the page. -->
        <aside
            v-if="openSession"
            class="fixed inset-y-0 end-0 z-40 flex w-full max-w-md flex-col border-s border-line bg-card shadow-e3"
            role="dialog"
            :aria-label="t('reports.team_test.transcript')"
        >
            <header class="flex items-center gap-2 border-b border-line px-4 py-3">
                <div class="min-w-0 flex-1">
                    <h2 class="truncate text-sm font-semibold" dir="auto">{{ openSession.label }}</h2>
                    <a
                        v-if="openSession.conversation_id"
                        :href="`/inbox?c=${openSession.conversation_id}`"
                        class="text-13 font-semibold text-brand-ink hover:underline"
                    >
                        {{ t('reports.team_test.open_in_inbox') }}
                    </a>
                </div>
                <IconAction :icon="X" :label="t('reports.team_test.close_transcript')" @click="openSession = null" />
            </header>

            <div class="flex-1 space-y-3 overflow-y-auto p-4">
                <SkeletonList v-if="loading" variant="cards" />
                <InlineError v-else-if="loadError" :message="loadError" @retry="openSession && show(openSession)" />
                <div v-for="line in transcript" :key="line.id" class="space-y-0.5">
                    <p class="text-xs font-semibold text-fg-3">{{ who(line) }} · {{ stamp(line.created_at) }}</p>
                    <p
                        v-if="line.body"
                        class="whitespace-pre-line rounded-lg px-3 py-2 text-13"
                        :class="line.sender === 'customer' ? 'bg-sunken' : 'bg-brand-soft'"
                        dir="auto"
                    >
                        {{ line.body }}
                    </p>
                    <p v-if="line.buttons.length" class="flex flex-wrap gap-1">
                        <span v-for="(b, i) in line.buttons" :key="i" class="rounded-full border border-line-strong px-2 py-0.5 text-xs" dir="auto">{{
                            b.title
                        }}</span>
                    </p>
                </div>
            </div>
        </aside>
    </AppLayout>
</template>
