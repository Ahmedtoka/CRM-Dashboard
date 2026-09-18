<script setup lang="ts">
import PageHeader from '@/components/crm/PageHeader.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import type {
    BotLearningNoteKind,
    BotLearningNoteRow,
    BotLearningReport,
    BotLearningReportRow,
    BotLearningToday,
    BotSuggestionRow,
} from '@/types/admin';
import { Head, router } from '@inertiajs/vue3';
import { Check, ExternalLink, Loader2, RefreshCw, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    reports: BotLearningReportRow[];
    report: BotLearningReport | null;
    today: BotLearningToday;
    todayNotes: BotLearningNoteRow[];
    intentLabels?: Record<string, string>;
}>();

const { t } = useI18n();
const toast = useToast();
const api = useApi();

const running = ref(false);
const deciding = ref<number | null>(null);
const tab = ref<'reports' | 'notes'>(new URL(window.location.href).searchParams.get('tab') === 'notes' ? 'notes' : 'reports');

function reload(): void {
    router.reload({ only: ['reports', 'report', 'today', 'todayNotes'] });
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
]);

/** The kinds in a fixed order, most useful first, so the tab never reshuffles. */
const KIND_ORDER: BotLearningNoteKind[] = ['agent_knowledge', 'unanswered', 'wrong_answer', 'new_phrasing', 'flow_friction'];

const notesByKind = computed(() =>
    KIND_ORDER.map((kind) => ({ kind, notes: props.todayNotes.filter((n) => n.kind === kind) })).filter((group) => group.notes.length),
);

const kindTone: Record<BotLearningNoteKind, string> = {
    agent_knowledge: 'bg-emerald-500/10 text-emerald-600',
    unanswered: 'bg-amber-500/10 text-amber-600',
    wrong_answer: 'bg-destructive/10 text-destructive',
    new_phrasing: 'bg-primary/10 text-primary',
    flow_friction: 'bg-muted text-muted-foreground',
};

const tabs = ['reports', 'notes'] as const;

function select(id: number): void {
    router.get('/settings/bot-learning', { report: id }, { preserveScroll: true });
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

const breadcrumbs = computed(() => [{ title: t('settings.bot_learning.title'), href: '/settings/bot-learning' }]);
const card = 'rounded-lg border border-border bg-card p-4 shadow-card';
const badge = 'rounded-full px-2 py-0.5 text-2xs font-medium';
</script>

<template>
    <Head :title="t('settings.bot_learning.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-6 p-3 md:p-6" dir="rtl">
            <PageHeader :title="t('settings.bot_learning.title')" :description="t('settings.bot_learning.description')">
                <button
                    type="button"
                    class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground disabled:opacity-60"
                    :disabled="running"
                    @click="run"
                >
                    <Loader2 v-if="running" class="size-3.5 animate-spin" aria-hidden="true" />
                    <RefreshCw v-else class="size-3.5" aria-hidden="true" />
                    {{ t('settings.bot_learning.run') }}
                </button>
            </PageHeader>

            <ul class="flex flex-wrap gap-2" :title="t('settings.bot_learning.today_cap', { cap: today.cap })">
                <li v-for="chip in todayChips" :key="chip" class="rounded-full bg-primary/10 px-3 py-1 text-xs font-medium text-primary">
                    {{ chip }}
                </li>
            </ul>

            <div class="flex gap-1 border-b border-border" role="tablist">
                <button
                    v-for="key in tabs"
                    :key="key"
                    type="button"
                    role="tab"
                    :aria-selected="tab === key"
                    class="-mb-px border-b-2 px-3 py-2 text-sm"
                    :class="
                        tab === key
                            ? 'border-primary font-semibold text-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground'
                    "
                    @click="tab = key"
                >
                    {{ t(`settings.bot_learning.tab_${key}`) }}
                    <span v-if="key === 'notes' && todayNotes.length" class="ms-1 text-primary">({{ todayNotes.length }})</span>
                </button>
            </div>

            <section v-if="tab === 'notes'" class="space-y-4">
                <p class="text-xs text-muted-foreground">{{ t('settings.bot_learning.notes_hint') }}</p>

                <p v-if="!todayNotes.length" :class="[card, 'text-center text-sm text-muted-foreground']">
                    {{ t('settings.bot_learning.notes_empty') }}
                </p>

                <div v-for="group in notesByKind" :key="group.kind" class="space-y-2">
                    <h2 class="flex items-center gap-2 text-sm font-semibold">
                        <span :class="[badge, kindTone[group.kind]]">{{ t(`settings.bot_learning.kinds.${group.kind}`) }}</span>
                        <span class="text-xs text-muted-foreground">{{ group.notes.length }}</span>
                    </h2>

                    <article v-for="note in group.notes" :key="note.id" :class="[card, 'space-y-2']">
                        <p class="text-sm leading-6">{{ note.summary }}</p>
                        <p v-if="note.quote" class="border-s-2 border-border ps-2 text-xs italic text-muted-foreground">« {{ note.quote }} »</p>
                        <p v-if="note.agent_answer" class="rounded-md bg-emerald-500/5 p-2 text-xs leading-5">
                            <span class="font-semibold">{{ t('settings.bot_learning.agent_answer') }}</span> {{ note.agent_answer }}
                        </p>
                        <footer class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                            <a
                                v-if="note.conversation_id"
                                :href="`/inbox?c=${note.conversation_id}`"
                                class="inline-flex items-center gap-1 text-primary hover:underline"
                            >
                                <ExternalLink class="size-3" aria-hidden="true" />{{ t('settings.bot_learning.open_conversation') }}
                                <span dir="ltr">#{{ note.conversation_id }}</span>
                            </a>
                            <span v-if="note.channel_account">{{ note.channel_account }}</span>
                        </footer>
                    </article>
                </div>
            </section>

            <p v-else-if="!reports.length" :class="[card, 'text-center text-sm text-muted-foreground']">
                {{ t('settings.bot_learning.empty') }}
            </p>

            <div v-else class="grid gap-4 md:grid-cols-[14rem_1fr]">
                <nav class="flex gap-2 overflow-x-auto md:flex-col md:overflow-visible" :aria-label="t('settings.bot_learning.title')">
                    <button
                        v-for="row in reports"
                        :key="row.id"
                        type="button"
                        class="shrink-0 rounded-md border px-3 py-2 text-right text-xs"
                        :class="row.id === report?.id ? 'border-primary bg-primary/5 font-semibold' : 'border-border hover:bg-muted'"
                        @click="select(row.id)"
                    >
                        <span dir="ltr">{{ row.report_date }}</span>
                        <span v-if="row.pending_count" class="ms-1 text-primary">({{ row.pending_count }})</span>
                        <span v-if="row.stats?.demo" class="block text-2xs text-muted-foreground">{{ t('settings.bot_learning.demo') }}</span>
                    </button>
                </nav>

                <div v-if="report" class="space-y-4">
                    <section :class="card">
                        <h2 class="mb-2 flex flex-wrap items-center gap-2 text-sm font-semibold">
                            {{ t('settings.bot_learning.summary') }}
                            <span
                                v-if="report.stats?.demo"
                                :class="[badge, 'bg-amber-500/10 text-amber-600']"
                                :title="t('settings.bot_learning.demo_hint')"
                            >
                                {{ t('settings.bot_learning.demo') }}
                            </span>
                        </h2>
                        <p class="whitespace-pre-line text-sm leading-6">{{ report.summary || '—' }}</p>
                        <p v-for="source in report.stats?.sources ?? []" :key="source.channel_account_id" class="mt-2 text-xs text-muted-foreground">
                            {{ t('settings.bot_learning.sources', { count: source.count, name: source.name }) }}
                        </p>
                        <ul v-if="statChips.length" class="mt-3 flex flex-wrap gap-2">
                            <li v-for="chip in statChips" :key="chip.label" :class="[badge, 'bg-muted text-muted-foreground']">
                                {{ chip.label }}: {{ chip.value }}
                            </li>
                        </ul>
                    </section>

                    <p v-if="!report.suggestions.length" :class="[card, 'text-center text-sm text-muted-foreground']">
                        {{ t('settings.bot_learning.no_suggestions') }}
                    </p>

                    <article v-for="s in report.suggestions" :key="s.id" :class="[card, 'space-y-3']">
                        <header class="flex flex-wrap items-center gap-2">
                            <span :class="[badge, 'bg-primary/10 text-primary']">{{ typeLabel(s.type) }}</span>
                            <span v-if="s.target_label" class="text-xs font-medium" dir="auto" :title="s.target ?? undefined">{{
                                s.target_label
                            }}</span>
                            <span v-else-if="s.target" class="text-xs font-medium" dir="ltr">{{ s.target }}</span>
                            <span
                                :class="[
                                    badge,
                                    'ms-auto',
                                    s.status === 'approved'
                                        ? 'bg-emerald-500/10 text-emerald-600'
                                        : s.status === 'rejected'
                                          ? 'bg-muted text-muted-foreground'
                                          : 'bg-amber-500/10 text-amber-600',
                                ]"
                            >
                                {{ t(`settings.bot_learning.status.${s.status}`) }}
                            </span>
                        </header>

                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <h3 class="mb-1 text-2xs font-semibold text-muted-foreground">{{ t('settings.bot_learning.current') }}</h3>
                                <p class="whitespace-pre-line rounded-md bg-muted/50 p-2 text-xs leading-5">{{ currentText(s) ?? '—' }}</p>
                            </div>
                            <div>
                                <h3 class="mb-1 text-2xs font-semibold text-muted-foreground">{{ t('settings.bot_learning.proposed') }}</h3>
                                <p class="whitespace-pre-line rounded-md bg-emerald-500/5 p-2 text-xs leading-5">{{ proposedText(s) }}</p>
                            </div>
                        </div>

                        <p v-if="s.reason" class="text-xs text-muted-foreground">{{ s.reason }}</p>

                        <p v-if="s.evidence?.quote" class="border-s-2 border-border ps-2 text-xs italic text-muted-foreground">
                            « {{ s.evidence.quote }} »
                        </p>

                        <p v-if="evidenceIds(s).length" class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="text-muted-foreground">{{ t('settings.bot_learning.evidence') }}</span>
                            <a v-for="id in evidenceIds(s)" :key="id" :href="`/inbox?c=${id}`" class="text-primary hover:underline" dir="ltr"
                                >#{{ id }}</a
                            >
                        </p>

                        <p v-if="s.error" class="text-xs text-destructive">{{ s.error }}</p>

                        <footer v-if="s.status === 'pending'" class="flex gap-2">
                            <button
                                type="button"
                                class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground disabled:opacity-60"
                                :disabled="deciding === s.id"
                                @click="decide(s, 'approve')"
                            >
                                <Check class="size-3.5" aria-hidden="true" />{{ t('settings.bot_learning.approve') }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-3 text-xs font-medium disabled:opacity-60"
                                :disabled="deciding === s.id"
                                @click="decide(s, 'reject')"
                            >
                                <X class="size-3.5" aria-hidden="true" />{{ t('settings.bot_learning.reject') }}
                            </button>
                        </footer>
                    </article>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
