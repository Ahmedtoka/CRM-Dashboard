<script setup lang="ts">
import CaseDrawer from '@/components/crm/cases/CaseDrawer.vue';
import CaseSlaChip from '@/components/crm/cases/CaseSlaChip.vue';
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FilterBar from '@/components/crm/FilterBar.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import IconAction from '@/components/crm/IconAction.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import StatCard from '@/components/crm/StatCard.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { Button } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { useUrlFilters } from '@/composables/useUrlFilters';
import { useVisitLoading } from '@/composables/useVisitLoading';
import AppLayout from '@/layouts/AppLayout.vue';
import { caseTypeTone } from '@/lib/caseStatus';
import { formatDateTime, formatSince } from '@/lib/format';
import type { Tone } from '@/lib/orderStatus';
import type { CaseType, FollowUpCounts, FollowUpNextAction, FollowUpRow } from '@/types/crm';
import { Head, router } from '@inertiajs/vue3';
import { CheckCheck, ListChecks, StickyNote, UserCheck } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/**
 * «المتابعة» (queue ops): every open case, who owns it and the one next step. The rows come from
 * `FollowUpList`; every action goes through the endpoints the Cases page already uses (PATCH the case,
 * POST a conversation note), then the list reloads.
 */
const TYPES: CaseType[] = ['return', 'exchange', 'return_exchange', 'complaint', 'cancel_edit', 'delivery_followup'];

/** What the next step means for the eye: an orphan is amber, a waiting customer blue, a passed SLA red. */
const NEXT_TONE: Record<FollowUpNextAction, Tone> = { assign: 'warning', reply: 'info', overdue: 'negative', resolve_check: 'neutral' };

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        rows: FollowUpRow[];
        counts: FollowUpCounts;
        team?: { id: number; name: string }[];
        /** The signed-in user: «استلمي» assigns to her, «بتاعتي» filters on her. */
        me: number;
    }>(),
    { team: () => [] },
);

const { t, locale } = useI18n();
const api = useApi();
const toast = useToast();
const { loading, track } = useVisitLoading();

// The filters live in the URL (shareable, back/forward); the server validates the same keys.
const { filters, set, clear, query } = useUrlFilters(
    {
        type: null as CaseType | null,
        /** A team member's id (owner or assignee); a string in the URL, coerced for the select. */
        owner: null as string | null,
        overdue: false as boolean,
        mine: false as boolean,
    },
    { keep: ['open'] },
);
watch(query, (q) => {
    router.get('/cases/follow-up', q, track({ only: ['rows', 'counts', 'filters'], preserveState: true, preserveScroll: true, replace: true }));
});
const apply = (patch: Partial<typeof filters.value>) => set(patch);
const selectValue = (event: Event) => (event.target as HTMLSelectElement).value || null;

const ownerName = (id: string | null) => props.team.find((m) => String(m.id) === id)?.name ?? null;
const chips = computed(() => {
    const f = filters.value;
    const out: { key: string; label: string }[] = [];
    if (f.type) out.push({ key: 'type', label: t(`cases.types.${f.type}`) });
    if (f.owner) out.push({ key: 'owner', label: t('cases.follow_up.chip_owner', { name: ownerName(f.owner) ?? f.owner }) });
    if (f.overdue) out.push({ key: 'overdue', label: t('cases.follow_up.chip_overdue') });
    if (f.mine) out.push({ key: 'mine', label: t('cases.follow_up.chip_mine') });
    return out;
});
const filtered = computed(() => chips.value.length > 0);
const summary = computed(() => [t('ui.results', { n: props.rows.length }), ...chips.value.map((c) => c.label)].join(' · '));

function removeChip(key: string): void {
    if (key === 'type') apply({ type: null });
    else if (key === 'owner') apply({ owner: null });
    else if (key === 'overdue') apply({ overdue: false });
    else apply({ mine: false });
}

const now = Date.now();

const columns = computed<Column[]>(() => [
    { key: 'type', label: t('cases.follow_up.columns.type'), primary: true },
    { key: 'customer', label: t('cases.follow_up.columns.customer') },
    { key: 'owner', label: t('cases.follow_up.columns.owner') },
    { key: 'opened', label: t('cases.follow_up.columns.opened'), hideOnMobile: true },
    { key: 'sla', label: t('cases.follow_up.columns.sla') },
    { key: 'last_message', label: t('cases.follow_up.columns.last_message'), wide: true },
    { key: 'next', label: t('cases.follow_up.columns.next') },
    { key: 'actions', label: t('cases.follow_up.columns.actions'), align: 'end' },
]);

/** A passed SLA tints the whole row, so the top of the list reads as urgent before any chip is read. */
const rowClass = (row: FollowUpRow) => (row.sla.state === 'overdue' ? 'bg-danger-soft/40' : undefined);

// The drawer: the full case (summary, items, photos) on a row click, as on the Cases page.
const selectedCaseId = ref<number | null>(null);
const drawerOpen = ref(false);

function openCase(row: FollowUpRow): void {
    selectedCaseId.value = row.id;
    drawerOpen.value = true;
}

function reload(): void {
    router.reload({ only: ['rows', 'counts'] });
}

// Row actions. One at a time per row; the row click is swallowed so the drawer stays shut.
const busy = ref<Record<number, 'take' | 'resolve' | undefined>>({});

async function take(row: FollowUpRow, event: MouseEvent): Promise<void> {
    event.stopPropagation();
    busy.value = { ...busy.value, [row.id]: 'take' };
    try {
        await api.patch(`/cases/${row.id}`, { assigned_to_id: props.me });
        toast.push(t('cases.follow_up.taken_toast', { id: row.id }));
        reload();
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    } finally {
        busy.value = { ...busy.value, [row.id]: undefined };
    }
}

/** «اتحلّت» runs the existing resolve flow: points to the owner, and the customer hears her case is done. */
async function resolve(row: FollowUpRow, event: MouseEvent): Promise<void> {
    event.stopPropagation();
    if (!window.confirm(t('cases.follow_up.resolve_confirm', { id: row.id }))) return;
    busy.value = { ...busy.value, [row.id]: 'resolve' };
    try {
        await api.patch(`/cases/${row.id}`, { status: 'resolved' });
        toast.push(t('cases.follow_up.resolved_toast', { id: row.id }));
        reload();
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    } finally {
        busy.value = { ...busy.value, [row.id]: undefined };
    }
}

// «ملاحظة»: a team note on the case conversation (the inbox's own notes endpoint).
const noteFor = ref<FollowUpRow | null>(null);
const noteBody = ref('');
const noteBusy = ref(false);
const noteError = ref<string | null>(null);
const noteOpen = computed({ get: () => noteFor.value !== null, set: (open: boolean) => (open ? undefined : (noteFor.value = null)) });

function openNote(row: FollowUpRow, event: MouseEvent): void {
    event.stopPropagation();
    noteBody.value = '';
    noteError.value = null;
    noteFor.value = row;
}

async function saveNote(): Promise<void> {
    const row = noteFor.value;
    const body = noteBody.value.trim();
    if (!row || body === '') return;
    noteBusy.value = true;
    noteError.value = null;
    try {
        await api.post(`/inbox/conversations/${row.conversation_id}/notes`, { body });
        toast.push(t('cases.follow_up.note_saved'));
        noteFor.value = null;
        reload();
    } catch (e) {
        noteError.value = apiErrorMessage(e, t('common.error'));
    } finally {
        noteBusy.value = false;
    }
}

const breadcrumbs = computed(() => [
    { title: t('cases.title'), href: '/cases' },
    { title: t('cases.follow_up.title'), href: '/cases/follow-up' },
]);
const selectClass = 'h-9 w-full rounded-md border border-input bg-background px-2 text-xs sm:w-auto';
</script>

<template>
    <Head :title="t('cases.follow_up.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full min-w-0 max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('cases.follow_up.title')" :description="t('cases.follow_up.description')" />

            <!-- The four tiles: the whole open set, whatever the filters say. -->
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4" data-follow-up-tiles>
                <StatCard :label="t('cases.follow_up.tiles.open')" :value="counts.open" />
                <StatCard :label="t('cases.follow_up.tiles.overdue')" :value="counts.overdue" :tone="counts.overdue > 0 ? 'negative' : 'default'" />
                <StatCard
                    :label="t('cases.follow_up.tiles.unassigned')"
                    :value="counts.unassigned"
                    :tone="counts.unassigned > 0 ? 'warning' : 'default'"
                />
                <StatCard :label="t('cases.follow_up.tiles.mine')" :value="counts.mine" />
            </div>

            <FilterBar :chips="chips" :summary="summary" @remove="removeChip" @clear="clear()">
                <template #inline>
                    <select
                        :value="filters.type ?? ''"
                        :class="selectClass"
                        :aria-label="t('cases.type_all')"
                        data-filter="type"
                        @change="apply({ type: selectValue($event) as CaseType | null })"
                    >
                        <option value="">{{ t('cases.type_all') }}</option>
                        <option v-for="ty in TYPES" :key="ty" :value="ty">{{ t(`cases.types.${ty}`) }}</option>
                    </select>
                    <select
                        :value="filters.owner ?? ''"
                        :class="selectClass"
                        :aria-label="t('cases.follow_up.columns.owner')"
                        data-filter="owner"
                        @change="apply({ owner: selectValue($event) })"
                    >
                        <option value="">{{ t('cases.follow_up.owner_all') }}</option>
                        <option v-for="member in team" :key="member.id" :value="String(member.id)">{{ member.name }}</option>
                    </select>
                    <Button
                        type="button"
                        size="sm"
                        :variant="filters.overdue ? 'default' : 'outline'"
                        :aria-pressed="filters.overdue"
                        data-filter="overdue"
                        @click="apply({ overdue: !filters.overdue })"
                    >
                        {{ t('cases.follow_up.filter_overdue') }}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :variant="filters.mine ? 'default' : 'outline'"
                        :aria-pressed="filters.mine"
                        data-filter="mine"
                        @click="apply({ mine: !filters.mine })"
                    >
                        {{ t('cases.follow_up.filter_mine') }}
                    </Button>
                </template>
            </FilterBar>

            <div class="min-w-0">
                <DataTable
                    table-id="cases-follow-up"
                    card-grid
                    :columns="columns"
                    :rows="rows"
                    clickable
                    :loading="loading"
                    :caption="t('cases.follow_up.title')"
                    :row-class="rowClass"
                    @row-click="openCase"
                >
                    <template #empty>
                        <EmptyState
                            :icon="ListChecks"
                            :title="t('cases.follow_up.empty')"
                            :body="filtered ? t('cases.follow_up.empty_filtered') : t('cases.follow_up.empty_body')"
                        >
                            <template v-if="filtered" #action>
                                <Button variant="outline" size="sm" @click="clear()">{{ t('ui.clear_filters') }}</Button>
                            </template>
                        </EmptyState>
                    </template>
                    <template #cell-type="{ row }">
                        <span class="flex min-w-0 flex-col items-start gap-1">
                            <StatusChip :label="row.type_label" :tone="caseTypeTone[row.type] ?? 'neutral'" />
                            <span class="text-xs tabular-nums text-fg-3" dir="ltr">#{{ row.id }}</span>
                        </span>
                    </template>
                    <template #cell-customer="{ row }">
                        <span class="block max-w-[12rem] truncate font-semibold" dir="auto">{{ row.customer?.name ?? '—' }}</span>
                        <span class="block text-xs text-fg-2">
                            <bdi v-if="row.customer?.phone" dir="ltr" class="tabular-nums">{{ row.customer.phone }}</bdi>
                            <template v-if="row.order_number">
                                <template v-if="row.customer?.phone"> · </template>{{ t('cases.columns.order') }}
                                <bdi dir="ltr" class="tabular-nums">{{ row.order_number }}</bdi>
                            </template>
                        </span>
                    </template>
                    <template #cell-owner="{ row }">
                        <template v-if="row.owner || row.assigned_to">
                            <span class="block max-w-[10rem] truncate" dir="auto">{{ (row.owner ?? row.assigned_to)?.name }}</span>
                            <span
                                v-if="row.owner && row.assigned_to && row.assigned_to.id !== row.owner.id"
                                class="block max-w-[10rem] truncate text-xs text-fg-2"
                                dir="auto"
                                >{{ t('cases.follow_up.assigned_to', { name: row.assigned_to.name }) }}</span
                            >
                        </template>
                        <span v-else class="text-fg-3">{{ t('cases.unassigned') }}</span>
                    </template>
                    <template #cell-opened="{ row }">
                        <span class="whitespace-nowrap tabular-nums text-fg-2" :title="formatDateTime(row.opened_at, locale)">{{
                            formatSince(row.opened_at, locale, now)
                        }}</span>
                    </template>
                    <template #cell-sla="{ row }">
                        <span class="flex flex-wrap items-center gap-1">
                            <CaseSlaChip v-if="row.sla.state" :sla="row.sla" />
                            <span v-else class="text-xs text-fg-3">{{ t('cases.follow_up.no_sla') }}</span>
                            <span v-if="row.sla.due_at" class="text-xs tabular-nums text-fg-3" :title="formatDateTime(row.sla.due_at, locale)">{{
                                formatDateTime(row.sla.due_at, locale)
                            }}</span>
                        </span>
                    </template>
                    <template #cell-last_message="{ row }">
                        <span
                            v-if="row.last_customer_message"
                            class="block max-w-[22rem] truncate text-13"
                            dir="auto"
                            :title="row.last_customer_message"
                        >
                            {{ row.last_customer_message }}
                        </span>
                        <span v-else class="block text-13 text-fg-3">{{ t('cases.follow_up.no_message') }}</span>
                        <span
                            v-if="row.last_customer_message_at"
                            class="block text-xs tabular-nums text-fg-2"
                            :title="formatDateTime(row.last_customer_message_at, locale)"
                            >{{ formatSince(row.last_customer_message_at, locale, now) }}</span
                        >
                        <span
                            v-if="row.last_note"
                            class="mt-1 block max-w-[22rem] truncate text-xs text-note-ink"
                            dir="auto"
                            :title="`${row.last_note.author?.name ?? ''}: ${row.last_note.body}`"
                        >
                            {{ t('cases.follow_up.note_by', { name: row.last_note.author?.name ?? '—' }) }}: {{ row.last_note.body }}
                        </span>
                    </template>
                    <template #cell-next="{ row }">
                        <StatusChip :label="t(`cases.follow_up.next_action.${row.next_action}`)" :tone="NEXT_TONE[row.next_action]" />
                    </template>
                    <template #cell-actions="{ row }">
                        <span class="flex items-center justify-end gap-0.5" data-follow-up-actions>
                            <IconAction
                                :label="row.assigned_to?.id === me ? t('cases.follow_up.actions.taken') : t('cases.follow_up.actions.take')"
                                :icon="UserCheck"
                                :variant="row.assigned_to?.id === me ? 'ghost' : 'primary'"
                                :disabled="row.assigned_to?.id === me"
                                :loading="busy[row.id] === 'take'"
                                data-action="take"
                                @click="take(row, $event)"
                            />
                            <IconAction
                                :label="t('cases.follow_up.actions.note')"
                                :icon="StickyNote"
                                :loading="false"
                                data-action="note"
                                @click="openNote(row, $event)"
                            />
                            <IconAction
                                :label="t('cases.follow_up.actions.resolve')"
                                :icon="CheckCheck"
                                variant="outline"
                                :loading="busy[row.id] === 'resolve'"
                                data-action="resolve"
                                @click="resolve(row, $event)"
                            />
                        </span>
                    </template>
                </DataTable>
            </div>
        </div>

        <CaseDrawer v-model:open="drawerOpen" :case-id="selectedCaseId" :team="team" @updated="reload" />

        <FormDialog
            v-model:open="noteOpen"
            :title="t('cases.follow_up.note_title', { id: noteFor?.id ?? '' })"
            :description="t('cases.follow_up.note_hint')"
            :busy="noteBusy"
            :error="noteError"
            :disabled="noteBody.trim() === ''"
            @submit="saveNote"
        >
            <textarea
                v-model="noteBody"
                rows="4"
                maxlength="5000"
                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                :placeholder="t('cases.follow_up.note_placeholder')"
                :aria-label="t('cases.follow_up.actions.note')"
                dir="auto"
                data-note-body
            ></textarea>
        </FormDialog>
    </AppLayout>
</template>
