<script setup lang="ts">
import EmptyState from '@/components/crm/EmptyState.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import SectionTabs from '@/components/crm/SectionTabs.vue';
import SkeletonList from '@/components/crm/SkeletonList.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { Button } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { useVisitLoading } from '@/composables/useVisitLoading';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatDate } from '@/lib/format';
import { carryFilters, sectionTabs } from '@/lib/sectionTabs';
import type {
    BotLearningNoteKind,
    BotLearningNoteRow,
    BotLearningReport,
    BotLearningReportRow,
    BotLearningSource,
    BotLearningToday,
    BotSuggestionRow,
} from '@/types/admin';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Check, Clock, ExternalLink, GraduationCap, NotebookPen, Pencil, RefreshCw, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    reports: BotLearningReportRow[];
    report: BotLearningReport | null;
    today: BotLearningToday;
    todayNotes: BotLearningNoteRow[];
    source: BotLearningSource | null;
    intentLabels?: Record<string, string>;
}>();

const { t, dir, locale } = useI18n();
const toast = useToast();
const api = useApi();

const running = ref(false);
const deciding = ref<number | null>(null);
const page = usePage();

/** build-r2 assumption 5: the tab lives in the URL (/bot/learning?tab=notes) and the shared link strip switches it. */
const tab = computed<'reports' | 'notes'>(() => (new URLSearchParams(page.url.split('?')[1] ?? '').get('tab') === 'notes' ? 'notes' : 'reports'));
const pending = computed(() => props.reports.reduce((sum, r) => sum + (r.pending_count ?? 0), 0));
// The source and the picked report stay when switching tabs.
const tabs = computed(() => carryFilters(sectionTabs.botLearning(t, pending.value), page.url));
const lastReview = computed(() => (props.reports[0] ? formatDate(props.reports[0].report_date, locale.value) : null));

function reload(): void {
    router.reload({ only: ['reports', 'report', 'today', 'todayNotes'] });
}

/**
 * Live / test (design 2026-09-21 §5). The filter is a page visit, not a client-side
 * hide: the server decides which notes and which suggestions belong to each source.
 */
const SOURCES: (BotLearningSource | null)[] = [null, 'live', 'test'];

function pickSource(value: BotLearningSource | null): void {
    const query = new URLSearchParams(window.location.search);
    if (value) query.set('source', value);
    else query.delete('source');
    router.get(`/bot/learning${query.toString() ? `?${query}` : ''}`, {}, { preserveScroll: true });
}

function sourceLabel(value: BotLearningSource | null): string {
    return value === null ? t('settings.bot_learning.source.all') : t(`settings.bot_learning.source.${value}`);
}

/** Review costs are cents a day, so small amounts keep a third decimal. */
function money(value: number | undefined): string {
    const v = value ?? 0;
    return v > 0 && v < 0.1 ? v.toFixed(3) : v.toFixed(2);
}

/** Learning v2 §2: today's reviews, notes and spend, above both tabs. */
const todayChips = computed(() => [
    t('settings.bot_learning.today_reviewed', { count: props.today.reviewed }),
    t('settings.bot_learning.today_notes', { count: props.today.notes }),
    t('settings.bot_learning.today_cost', { cost: money(props.today.cost_usd) }),
    `${t('settings.bot_learning.source.live')} ${formatCount(props.today.live, locale.value)} · ${t('settings.bot_learning.source.test')} ${formatCount(props.today.test, locale.value)}`,
]);

/** The kinds in a fixed order, most useful first, so the tab never reshuffles. */
const KIND_ORDER: BotLearningNoteKind[] = ['agent_knowledge', 'unanswered', 'wrong_answer', 'new_phrasing', 'flow_friction'];

const notesByKind = computed(() =>
    KIND_ORDER.map((kind) => ({ kind, notes: props.todayNotes.filter((n) => n.kind === kind) })).filter((group) => group.notes.length),
);

const kindTone: Record<BotLearningNoteKind, 'ok' | 'warn' | 'danger' | 'brand' | 'neutral'> = {
    agent_knowledge: 'ok',
    unanswered: 'warn',
    wrong_answer: 'danger',
    new_phrasing: 'brand',
    flow_friction: 'neutral',
};

const statusTone = { pending: 'warn', approved: 'ok', rejected: 'neutral' } as const;

/** «عدّلي»: there is no edit-the-proposal endpoint, so it opens the place where that text lives. */
const EDIT_HREF: Record<BotSuggestionRow['type'], string> = {
    script_text: '/bot/replies',
    new_faq: '/bot/knowledge',
    intent_keywords: '/bot/intents',
    flow_step: '/bot/flows',
};

// Picking another day's report is a visit; its panel shows a skeleton until the report lands.
const { loading: loadingReport, track } = useVisitLoading();

function select(id: number): void {
    router.get('/bot/learning', { report: id }, track({ preserveScroll: true }));
}

async function run(): Promise<void> {
    running.value = true;
    try {
        const { data } = await api.post<{ data: { status: string; suggestions: number } }>('/settings/bot-learning/run');
        // A quiet day is not a success: say so instead of implying a report was written.
        if (data.data.status === 'skipped') toast.push(t('settings.bot_learning.run_skipped'));
        else toast.push(t('settings.bot_learning.run_done', { count: data.data.suggestions }));
        reload();
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    } finally {
        running.value = false;
    }
}

async function decide(suggestion: BotSuggestionRow, action: 'approve' | 'reject'): Promise<void> {
    deciding.value = suggestion.id;
    try {
        await api.post(`/settings/bot-suggestions/${suggestion.id}/${action}`);
        toast.push(action === 'approve' ? t('settings.bot_learning.approved') : t('settings.bot_learning.rejected'));
        if (action === 'approve' && suggestion.type === 'flow_step') toast.push(t('settings.bot_learning.flow_draft_notice'));
        reload();
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
        reload();
    } finally {
        deciding.value = null;
    }
}

/** The stats chips, in a fixed order so the row never jumps between days. */
const statChips = computed(() => {
    const stats = props.report?.stats ?? {};
    const rows: { label: string; value: string }[] = [];

    for (const key of ['conversations', 'notes', 'handovers', 'cases'] as const) {
        if (typeof stats[key] === 'number') rows.push({ label: t(`settings.bot_learning.stats.${key}`), value: String(stats[key]) });
    }

    if (typeof stats.cost_usd === 'number') rows.push({ label: t('settings.bot_learning.stats.cost_usd'), value: `$${money(stats.cost_usd)}` });

    const top = stats.top_intents;
    if (Array.isArray(top) && top.length)
        rows.push({
            label: t('settings.bot_learning.stats.top_intents'),
            value: top.map((key) => props.intentLabels?.[String(key)] ?? String(key)).join('، '),
        });

    return rows;
});

/** One line of "what it says today" per suggestion type; null when the target vanished. */
function currentText(s: BotSuggestionRow): string | null {
    if (!s.current) return null;
    if (typeof s.current.body === 'string') return s.current.body;
    if (Array.isArray(s.current.keywords)) return s.current.keywords.join('، ');
    if (s.type === 'flow_step') return [s.current.text ?? '', ...(s.current.options ?? [])].filter(Boolean).join(' · ');
    return null;
}

/** The same line for the proposal, built from whichever keys the type uses. */
function proposedText(s: BotSuggestionRow): string {
    const p = s.proposed ?? {};
    if (typeof p.body === 'string' && s.type !== 'new_faq') return p.body;
    if (s.type === 'new_faq') return [p.title, p.body, (p.keywords ?? []).join('، ')].filter(Boolean).join(' — ');
    if (Array.isArray(p.add)) return p.add.join('، ');
    if (s.type === 'flow_step') return [p.text ?? '', ...(p.options ?? []).map((o) => `#${o.index}: ${o.title}`)].filter(Boolean).join(' · ');
    return '';
}

function typeLabel(type: string): string {
    return t(`settings.bot_learning.types.${type}`);
}

function evidenceIds(s: BotSuggestionRow): number[] {
    return s.evidence?.conversation_ids ?? [];
}

const breadcrumbs = computed(() => [
    { title: t('nav.bot'), href: '/bot' },
    { title: t('nav.bot_learning'), href: '/bot/learning' },
]);
const panel = 'rounded-lg border border-line bg-card';
</script>

<template>
    <Head :title="t('nav.bot_learning')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-4 p-3 md:p-6" :dir="dir">
            <PageHeader
                :title="t('nav.bot_learning')"
                :description="t('settings.bot_learning.description')"
                :breadcrumbs="[{ label: t('nav.bot'), href: '/bot' }, { label: t('nav.bot_learning') }]"
            >
                <template #extra>
                    <StatusChip v-if="lastReview" :icon="Clock" size="sm" :label="t('settings.bot_learning.last_review', { date: lastReview })" />
                </template>
                <Button type="button" variant="outline" class="gap-1.5" :loading="running" @click="run">
                    <RefreshCw class="size-4" aria-hidden="true" />
                    {{ t('settings.bot_learning.run') }}
                </Button>
            </PageHeader>

            <SectionTabs :tabs="tabs" :label="t('nav.bot_learning')" />

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <div class="flex flex-wrap gap-1.5" role="group" :aria-label="t('settings.bot_learning.source.all')">
                    <button
                        v-for="value in SOURCES"
                        :key="value ?? 'all'"
                        type="button"
                        class="inline-flex h-9 items-center rounded-full border px-3 text-13 font-semibold transition-colors"
                        :aria-pressed="props.source === value"
                        :class="
                            props.source === value ? 'border-brand bg-brand-soft text-brand-ink' : 'border-line bg-card text-fg-2 hover:bg-surface-2'
                        "
                        @click="pickSource(value)"
                    >
                        {{ sourceLabel(value) }}
                    </button>
                </div>
                <ul class="flex flex-wrap gap-x-3 gap-y-1 text-13 text-fg-2" :title="t('settings.bot_learning.today_cap', { cap: today.cap })">
                    <li v-for="chip in todayChips" :key="chip" class="tabular-nums">{{ chip }}</li>
                </ul>
            </div>

            <section v-if="tab === 'notes'" class="space-y-4">
                <p class="text-13 text-fg-2">{{ t('settings.bot_learning.notes_hint') }}</p>

                <div v-if="!todayNotes.length" :class="panel">
                    <EmptyState :icon="NotebookPen" :title="t('settings.bot_learning.notes_empty')" />
                </div>

                <section v-for="group in notesByKind" :key="group.kind" :class="panel" :aria-label="t(`settings.bot_learning.kinds.${group.kind}`)">
                    <header class="flex items-center gap-2 border-b border-line px-4 pb-2.5 pt-3.5">
                        <StatusChip :label="t(`settings.bot_learning.kinds.${group.kind}`)" :tone="kindTone[group.kind]" />
                        <span class="text-13 tabular-nums text-fg-3">{{ formatCount(group.notes.length, locale) }}</span>
                    </header>

                    <article v-for="note in group.notes" :key="note.id" class="space-y-2 border-b border-line px-4 py-3 last:border-b-0">
                        <p class="flex items-start gap-2 text-sm leading-6">
                            <StatusChip
                                v-if="note.source === 'test'"
                                size="sm"
                                tone="note"
                                :label="t('settings.bot_learning.source_badge.test')"
                                class="mt-0.5"
                            />
                            <span dir="auto">{{ note.summary }}</span>
                        </p>
                        <p v-if="note.quote" class="border-s border-line-strong ps-2 text-13 text-fg-2" dir="auto">«{{ note.quote }}»</p>
                        <p v-if="note.agent_answer" class="rounded-md bg-ok-soft px-3 py-2 text-13 leading-5">
                            <span class="font-semibold">{{ t('settings.bot_learning.agent_answer') }}</span> {{ note.agent_answer }}
                        </p>
                        <footer class="flex flex-wrap items-center gap-3 text-xs text-fg-3">
                            <a
                                v-if="note.conversation_id"
                                :href="`/inbox?c=${note.conversation_id}`"
                                class="inline-flex items-center gap-1 font-semibold text-brand-ink hover:underline"
                            >
                                <ExternalLink class="size-3" aria-hidden="true" />{{ t('settings.bot_learning.open_conversation') }}
                                <bdi dir="ltr">#{{ note.conversation_id }}</bdi>
                            </a>
                            <span v-if="note.channel_account">{{ note.channel_account }}</span>
                        </footer>
                    </article>
                </section>
            </section>

            <div v-else-if="!reports.length" :class="panel">
                <EmptyState :icon="GraduationCap" :title="t('settings.bot_learning.empty')" />
            </div>

            <div v-else class="grid gap-4 md:grid-cols-[12rem_minmax(0,1fr)]">
                <nav
                    class="scrollbar-none flex gap-1.5 overflow-x-auto md:flex-col md:overflow-visible"
                    :aria-label="t('settings.bot_learning.reports_label')"
                >
                    <button
                        v-for="row in reports"
                        :key="row.id"
                        type="button"
                        class="flex min-h-10 shrink-0 items-center gap-2 rounded-md border px-3 py-2 text-start text-13 transition-colors"
                        :class="
                            row.id === report?.id
                                ? 'border-brand bg-brand-soft font-semibold text-brand-ink'
                                : 'border-line bg-card hover:bg-surface-2'
                        "
                        :aria-current="row.id === report?.id ? 'true' : undefined"
                        @click="select(row.id)"
                    >
                        <span class="flex-1 tabular-nums">{{ formatDate(row.report_date, locale) }}</span>
                        <span v-if="row.pending_count" class="rounded-full bg-brand px-1.5 text-2xs font-bold tabular-nums text-brand-on"
                            >{{ formatCount(row.pending_count, locale)
                            }}<span class="sr-only"> {{ t('settings.bot_learning.status.pending') }}</span></span
                        >
                        <span v-if="row.stats?.demo" class="rounded-full bg-sunken px-1.5 text-2xs font-semibold text-fg-2">{{
                            t('settings.bot_learning.demo')
                        }}</span>
                    </button>
                </nav>

                <SkeletonList v-if="loadingReport" variant="cards" />
                <div v-else-if="report" class="min-w-0 space-y-4">
                    <section :class="[panel, 'p-4']" aria-labelledby="learning-summary-title">
                        <h2 id="learning-summary-title" class="mb-2 flex flex-wrap items-center gap-2 text-base font-bold">
                            {{ t('settings.bot_learning.summary') }}
                            <StatusChip
                                v-if="report.stats?.demo"
                                size="sm"
                                tone="warn"
                                :label="t('settings.bot_learning.demo')"
                                :title="t('settings.bot_learning.demo_hint')"
                            />
                        </h2>
                        <p class="whitespace-pre-line text-sm leading-6" dir="auto">{{ report.summary || '—' }}</p>
                        <p v-for="source in report.stats?.sources ?? []" :key="source.channel_account_id" class="mt-2 text-13 text-fg-2">
                            {{ t('settings.bot_learning.sources', { count: source.count, name: source.name }) }}
                        </p>
                        <dl v-if="statChips.length" class="mt-3 flex flex-wrap gap-x-5 gap-y-1.5 border-t border-line pt-3 text-13">
                            <div v-for="chip in statChips" :key="chip.label" class="flex gap-1.5">
                                <dt class="text-fg-2">{{ chip.label }}</dt>
                                <dd class="font-semibold tabular-nums" dir="auto">{{ chip.value }}</dd>
                            </div>
                        </dl>
                    </section>

                    <div v-if="!report.suggestions.length" :class="panel">
                        <EmptyState :icon="GraduationCap" :title="t('settings.bot_learning.no_suggestions')" />
                    </div>

                    <!-- SuggestionDiff (DESIGN §3): the question, how often, what it said vs what it would say, and the decision. -->
                    <section v-else :class="panel" :aria-label="t('section_tabs.learning.suggestions')">
                        <article
                            v-for="s in report.suggestions"
                            :key="s.id"
                            class="space-y-3 border-b border-line p-4 last:border-b-0"
                            :data-suggestion="s.id"
                        >
                            <header class="flex flex-wrap items-center gap-2">
                                <h3 class="min-w-0 text-sm font-bold" dir="auto">
                                    <template v-if="s.evidence?.quote">«{{ s.evidence.quote }}»</template>
                                    <template v-else-if="s.target_label">{{ s.target_label }}</template>
                                    <bdi v-else-if="s.target" dir="ltr">{{ s.target }}</bdi>
                                    <template v-else>{{ typeLabel(s.type) }}</template>
                                </h3>
                                <StatusChip size="sm" tone="outline" :label="typeLabel(s.type)" />
                                <StatusChip
                                    v-if="evidenceIds(s).length"
                                    size="sm"
                                    :label="t('settings.bot_learning.asked', { count: formatCount(evidenceIds(s).length, locale) })"
                                />
                                <StatusChip
                                    v-if="s.status !== 'pending'"
                                    size="sm"
                                    class="ms-auto"
                                    :tone="statusTone[s.status]"
                                    :label="t(`settings.bot_learning.status.${s.status}`)"
                                />
                            </header>

                            <p v-if="s.evidence?.quote && s.target_label" class="text-13 text-fg-2" dir="auto">{{ s.target_label }}</p>

                            <div class="grid gap-2 md:grid-cols-2">
                                <div class="rounded-md bg-danger-soft px-3 py-2.5">
                                    <h4 class="mb-1 text-xs font-semibold text-danger">{{ t('settings.bot_learning.current') }}</h4>
                                    <p class="whitespace-pre-line text-13 leading-6 text-foreground" dir="auto">{{ currentText(s) ?? '—' }}</p>
                                </div>
                                <div class="rounded-md bg-ok-soft px-3 py-2.5">
                                    <h4 class="mb-1 text-xs font-semibold text-ok">{{ t('settings.bot_learning.proposed') }}</h4>
                                    <p class="whitespace-pre-line text-13 leading-6 text-foreground" dir="auto">{{ proposedText(s) }}</p>
                                </div>
                            </div>

                            <p v-if="s.reason" class="text-13 text-fg-2">{{ s.reason }}</p>

                            <p v-if="evidenceIds(s).length" class="flex flex-wrap items-center gap-2 text-xs">
                                <span class="text-fg-3">{{ t('settings.bot_learning.evidence') }}</span>
                                <a
                                    v-for="id in evidenceIds(s)"
                                    :key="id"
                                    :href="`/inbox?c=${id}`"
                                    class="font-semibold text-brand-ink hover:underline"
                                    dir="ltr"
                                    >#{{ id }}</a
                                >
                            </p>

                            <p v-if="s.error" class="rounded-md bg-danger-soft px-3 py-2 text-13 text-danger" role="alert">{{ s.error }}</p>

                            <footer v-if="s.status === 'pending'" class="flex flex-wrap gap-2">
                                <Button type="button" size="sm" class="gap-1.5" :disabled="deciding === s.id" @click="decide(s, 'approve')">
                                    <Check class="size-4" aria-hidden="true" />{{ t('settings.bot_learning.approve') }}
                                </Button>
                                <Button as-child variant="secondary" size="sm" class="gap-1.5">
                                    <Link :href="EDIT_HREF[s.type]" :title="t('settings.bot_learning.edit_hint')">
                                        <Pencil class="size-4" aria-hidden="true" />{{ t('settings.bot_learning.edit') }}
                                    </Link>
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="gap-1.5"
                                    :disabled="deciding === s.id"
                                    @click="decide(s, 'reject')"
                                >
                                    <X class="size-4" aria-hidden="true" />{{ t('settings.bot_learning.reject') }}
                                </Button>
                            </footer>
                        </article>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
