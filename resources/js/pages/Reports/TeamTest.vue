<script setup lang="ts">
/**
 * Reports → «تجربة الفريق» (design 2026-09-21 §4).
 *
 * Three readings of the same testing, in the order the owner asks for them: the
 * headline counts, one row per run, and the funnel that says where each flow loses
 * people. A run opens its own transcript, with a way through to the inbox.
 */
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import StatCard from '@/components/crm/StatCard.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import type { TeamTestFunnel, TeamTestLinkOption, TeamTestSessionRow, TeamTestTotals, TeamTestTranscriptLine } from '@/types/admin';
import { Head, router } from '@inertiajs/vue3';
import { Download, X } from 'lucide-vue-next';
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
const toast = useToast();

const openSession = ref<TeamTestSessionRow | null>(null);
const transcript = ref<TeamTestTranscriptLine[]>([]);
const loading = ref(false);

const exportUrl = computed(() => `/reports/team-test/export${props.linkId ? `?link=${props.linkId}` : ''}`);

function pickLink(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    router.get('/reports/team-test', value ? { link: Number(value) } : {}, { preserveScroll: true });
}

async function show(row: TeamTestSessionRow): Promise<void> {
    openSession.value = row;
    transcript.value = [];
    loading.value = true;

    try {
        const { data } = await api.get(`/reports/team-test/sessions/${row.id}`);
        transcript.value = data.data.transcript;
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    } finally {
        loading.value = false;
    }
}

const stamp = (value: string | null): string =>
    value ? new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'short', timeStyle: 'short' }) : '—';

function duration(seconds: number): string {
    const m = Math.floor(seconds / 60);
    return m > 0 ? `${m}m ${seconds % 60}s` : `${seconds}s`;
}

function who(line: TeamTestTranscriptLine): string {
    if (line.sender === 'customer') return t('reports.team_test.you');
    if (line.sender === 'user') return line.author ?? t('reports.team_test.agent');
    return t('reports.team_test.bot');
}

const columns = computed<Column[]>(() => [
    { key: 'label', label: t('reports.team_test.tester') },
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

const breadcrumbs = computed(() => [{ title: t('reports.team_test.title'), href: '/reports/team-test' }]);
const select = 'h-8 rounded-md border border-input bg-background px-2 text-xs';
</script>

<template>
    <Head :title="t('reports.team_test.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-5 p-3 md:p-6">
            <PageHeader :title="t('reports.team_test.title')" :description="t('reports.team_test.description')">
                <select :value="props.linkId ?? ''" :class="select" :aria-label="t('reports.team_test.all_links')" @change="pickLink">
                    <option value="">{{ t('reports.team_test.all_links') }}</option>
                    <option v-for="link in props.links" :key="link.id" :value="link.id">{{ link.label }}</option>
                </select>
                <a :href="exportUrl" class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-3 text-xs font-medium hover:bg-muted">
                    <Download class="size-3.5" aria-hidden="true" />{{ t('reports.team_test.export') }}
                </a>
            </PageHeader>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
                <StatCard :label="t('reports.team_test.totals.sessions')" :value="props.totals.sessions" />
                <StatCard :label="t('reports.team_test.totals.testers')" :value="props.totals.testers" />
                <StatCard :label="t('reports.team_test.totals.messages')" :value="props.totals.messages" />
                <StatCard :label="t('reports.team_test.totals.finished')" :value="props.totals.finished" />
                <StatCard :label="t('reports.team_test.totals.cases')" :value="props.totals.cases" />
                <StatCard :label="t('reports.team_test.totals.handovers')" :value="props.totals.handovers" />
                <StatCard :label="t('reports.team_test.totals.avg_duration')" :value="duration(props.totals.avg_duration_seconds)" />
            </div>

            <DataTable
                :columns="columns"
                :rows="props.sessions"
                :empty="t('reports.team_test.empty')"
                :caption="t('reports.team_test.title')"
                clickable
                @row-click="show"
            >
                <template #cell-label="{ row }"><span class="font-medium" dir="auto">{{ row.label }}</span></template>
                <template #cell-started_at="{ row }"><span class="tabular-nums">{{ stamp(row.started_at) }}</span></template>
                <template #cell-duration_seconds="{ row }"><span class="tabular-nums">{{ duration(row.duration_seconds) }}</span></template>
                <template #cell-messages_total="{ row }">
                    <span class="tabular-nums">{{ row.messages_total }}</span>
                </template>
                <template #cell-flows="{ row }">
                    <span v-if="!row.flows.length" class="text-muted-foreground">—</span>
                    <span v-else class="flex flex-wrap gap-1">
                        <span v-for="flow in row.flows" :key="flow.key" class="rounded-full bg-muted px-2 py-0.5 text-2xs" dir="auto">{{ flow.title }}</span>
                    </span>
                </template>
                <template #cell-last_step="{ row }"><code class="text-2xs" dir="ltr">{{ row.last_step ?? '—' }}</code></template>
                <template #cell-outcome="{ row }">
                    <span
                        class="rounded-full px-2 py-0.5 text-2xs font-medium"
                        :class="row.finished ? 'bg-emerald-500/10 text-emerald-600' : 'bg-amber-500/10 text-amber-600'"
                    >
                        {{ row.finished ? t('reports.team_test.finished') : t('reports.team_test.dropped') }}
                    </span>
                </template>
            </DataTable>

            <!-- The funnel: how many runs reached each step, in the flow's own order. -->
            <section v-if="props.funnels.length" class="space-y-3">
                <h2 class="text-sm font-semibold">{{ t('reports.team_test.funnel') }}</h2>

                <article v-for="funnel in props.funnels" :key="funnel.key" class="rounded-lg bg-card p-4 shadow-card">
                    <header class="mb-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <h3 class="text-sm font-semibold" dir="auto">{{ funnel.title }}</h3>
                        <p class="text-xs text-muted-foreground">
                            {{ t('reports.team_test.funnel_entered') }} {{ funnel.entered }} ·
                            {{ t('reports.team_test.funnel_finished') }} {{ funnel.finished }}
                        </p>
                    </header>

                    <ol class="space-y-1.5">
                        <li v-for="step in funnel.steps" :key="step.id" class="grid grid-cols-[minmax(0,10rem)_1fr_auto] items-center gap-3 text-xs">
                            <code class="truncate text-2xs text-muted-foreground" dir="ltr">{{ step.id }}</code>
                            <span class="h-2 rounded-full bg-muted">
                                <span
                                    class="block h-2 rounded-full bg-primary"
                                    :style="{ width: `${funnel.entered > 0 ? Math.round((step.reached / funnel.entered) * 100) : 0}%` }"
                                />
                            </span>
                            <span class="tabular-nums">
                                {{ step.reached }}
                                <span v-if="step.dropped" class="text-amber-600">· {{ t('reports.team_test.funnel_dropped') }} {{ step.dropped }}</span>
                            </span>
                        </li>
                    </ol>

                    <p v-if="funnel.top_drop_offs.length" class="mt-3 flex flex-wrap items-center gap-2 text-2xs text-muted-foreground">
                        <span>{{ t('reports.team_test.top_drop_offs') }}</span>
                        <code v-for="drop in funnel.top_drop_offs" :key="drop.id" class="rounded bg-amber-500/10 px-1.5 py-0.5 text-amber-700" dir="ltr">
                            {{ drop.id }} ({{ drop.dropped }})
                        </code>
                    </p>
                </article>
            </section>
        </div>

        <!-- The transcript of one run, read straight down the page. -->
        <aside
            v-if="openSession"
            class="fixed inset-y-0 end-0 z-40 flex w-full max-w-md flex-col border-s border-border bg-card shadow-xl"
            role="dialog"
            :aria-label="t('reports.team_test.transcript')"
        >
            <header class="flex items-center gap-2 border-b border-border px-4 py-3">
                <div class="min-w-0 flex-1">
                    <h2 class="truncate text-sm font-semibold" dir="auto">{{ openSession.label }}</h2>
                    <a
                        v-if="openSession.conversation_id"
                        :href="`/inbox?c=${openSession.conversation_id}`"
                        class="text-xs text-primary hover:underline"
                    >
                        {{ t('reports.team_test.open_in_inbox') }}
                    </a>
                </div>
                <button type="button" class="rounded p-1.5 text-muted-foreground hover:bg-muted" :aria-label="t('reports.team_test.close_transcript')" @click="openSession = null">
                    <X class="size-4" />
                </button>
            </header>

            <div class="flex-1 space-y-3 overflow-y-auto p-4">
                <p v-if="loading" class="text-xs text-muted-foreground">…</p>
                <div v-for="line in transcript" :key="line.id" class="space-y-0.5">
                    <p class="text-2xs font-semibold text-muted-foreground">{{ who(line) }} · {{ stamp(line.created_at) }}</p>
                    <p v-if="line.body" class="whitespace-pre-line rounded-lg bg-muted/60 px-3 py-2 text-xs" dir="auto">{{ line.body }}</p>
                    <p v-if="line.buttons.length" class="flex flex-wrap gap-1">
                        <span v-for="(b, i) in line.buttons" :key="i" class="rounded-full border border-border px-2 py-0.5 text-2xs" dir="auto">{{ b.title }}</span>
                    </p>
                </div>
            </div>
        </aside>
    </AppLayout>
</template>
