<script setup lang="ts">
/**
 * Ads setup › الحسابات (F6): summary tiles, one filter (range + accounts) applied by «اعرض», one «سنك» with an account
 * picker and one overall progress bar, the connections, then one accounts table whose row actions are icons.
 */
import AdsSetupTabs from '@/components/ads/AdsSetupTabs.vue';
import PlatformChip from '@/components/ads/PlatformChip.vue';
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import DateInput from '@/components/crm/DateInput.vue';
import DateRangePicker from '@/components/crm/DateRangePicker.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import IconAction from '@/components/crm/IconAction.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import ProgressBar from '@/components/crm/ProgressBar.vue';
import RelativeTime from '@/components/crm/RelativeTime.vue';
import StatCard from '@/components/crm/StatCard.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { useVisiblePoll } from '@/composables/useVisiblePoll';
import AppLayout from '@/layouts/AppLayout.vue';
import { adAccountActive, adAccountStatusLabel, formatAdsMoney, formatDayLong } from '@/lib/ads';
import { useNow } from '@/composables/useNow';
import { cairoToday, formatCount, formatSince } from '@/lib/format';
import type {
    AdAccountRow,
    AdConnectionRow,
    AdPlatformDefinition,
    AdsAccountsProps,
    AdSyncStartResponse,
    AdSyncState,
    AdSyncStatusResponse,
} from '@/types/ads';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Check,
    ChevronDown,
    History,
    Hourglass,
    ListChecks,
    LoaderCircle,
    MoreHorizontal,
    Pause,
    Pencil,
    Play,
    Plug,
    Plus,
    RefreshCw,
    ShieldCheck,
    Trash2,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps<AdsAccountsProps>();

const { t, locale } = useI18n();
const toast = useToast();
const api = useApi();
const n = (v: number) => formatCount(v, locale.value);
const money = (value: number, currency: string) => formatAdsMoney(value, locale.value, currency);

/* ---------------- filter: drafted here, applied by «اعرض» ---------------- */
const range = ref({ from: props.filters.from, to: props.filters.to });
const pickedFilter = ref<number[]>([...props.filters.accounts]);
watch(
    () => props.filters,
    (f) => {
        range.value = { from: f.from, to: f.to };
        pickedFilter.value = [...f.accounts];
    },
);
const filterDirty = computed(
    () =>
        range.value.from !== props.filters.from ||
        range.value.to !== props.filters.to ||
        [...pickedFilter.value].sort().join(',') !== [...props.filters.accounts].sort().join(','),
);
const accountsLabel = computed(() =>
    pickedFilter.value.length
        ? t('ads.accounts.filter_accounts_n', { n: n(pickedFilter.value.length), total: n(props.account_options.length) })
        : t('ads.accounts.filter_accounts_all'),
);
function toggleFilterAccount(id: number): void {
    pickedFilter.value = pickedFilter.value.includes(id) ? pickedFilter.value.filter((x) => x !== id) : [...pickedFilter.value, id];
}
const loadingView = ref(false);
function show(): void {
    const query: Record<string, string> = { from: range.value.from, to: range.value.to };
    if (pickedFilter.value.length) query.accounts = [...pickedFilter.value].sort((a, b) => a - b).join(',');
    router.get('/ads/accounts', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loadingView.value = true),
        onFinish: () => (loadingView.value = false),
    });
}
const rangeLabel = computed(() =>
    props.filters.from === props.filters.to
        ? formatDayLong(props.filters.from, locale.value)
        : `${formatDayLong(props.filters.from, locale.value)} – ${formatDayLong(props.filters.to, locale.value)}`,
);

/* ---------------- summary tiles ---------------- */
const spendTile = computed(() =>
    props.summary.spend.length ? props.summary.spend.map((s) => money(s.amount, s.currency)).join(' · ') : money(0, 'EGP'),
);
const clock = useNow();
const lastSyncTile = computed(() => (props.summary.last_sync ? formatSince(props.summary.last_sync, locale.value, clock.value) : null));
const linkRateTile = computed(() => (props.link_rate.rate === null ? '—' : `${Math.round(props.link_rate.rate * 100)}%`));
const linkRateHint = computed(() =>
    props.link_rate.rate === null
        ? t('ads.accounts.link_rate_none')
        : t('ads.accounts.link_rate_hint', { linked: n(props.link_rate.linked), orders: n(props.link_rate.orders), days: n(props.link_rate.days) }),
);

/* ---------------- one «سنك»: picker → dispatch → one overall bar ---------------- */
const pickerOpen = ref(false);
const syncScope = ref<'all' | 'some'>('all');
const syncPicked = ref<number[]>([]);
/** Only accounts that can sync: active, on a connection that is neither stopped nor waiting for a new token. */
const syncOptions = computed(() => props.account_options.filter((o) => o.can_sync));
const canSyncId = (id: number) => syncOptions.value.some((o) => o.id === id);
function toggleSyncAccount(id: number): void {
    syncPicked.value = syncPicked.value.includes(id) ? syncPicked.value.filter((x) => x !== id) : [...syncPicked.value, id];
}
const canStart = computed(() => (syncScope.value === 'all' ? syncOptions.value.length > 0 : syncPicked.value.length > 0));

/** No state change for this long: stop polling and point to the sync page (the worker may be down or busy). */
const STALL_MS = 10 * 60 * 1000;
const tracked = ref<{ since: string; ids: number[] } | null>(null);
const status = ref<AdSyncStatusResponse | null>(null);
const starting = ref(false);
const stalled = ref(false);
let lastSignature = '';
let lastChangeAt = 0;
const connectionErrors = ref<{ connection: string; message: string }[]>([]);
const syncRunning = computed(() => tracked.value !== null && !(status.value?.finished ?? false) && !stalled.value);

function track(since: string, ids: number[]): void {
    tracked.value = { since, ids };
    status.value = null;
    stalled.value = false;
    lastSignature = '';
    lastChangeAt = Date.now();
}

async function startSync(ids: number[] | null): Promise<void> {
    if (starting.value) return;
    starting.value = true;
    try {
        const { data } = await api.post<AdSyncStartResponse>('/ads/accounts/sync', ids === null ? {} : { accounts: ids });
        pickerOpen.value = false;
        connectionErrors.value = data.errors;
        if (!data.accounts.length) {
            toast.push(t('ads.accounts.sync_nothing'), 'error');
            return;
        }
        // A sync started while another is still going joins the same bar.
        const keep = syncRunning.value && tracked.value ? tracked.value : null;
        track(keep?.since ?? data.since, [...new Set([...(keep?.ids ?? []), ...data.accounts])]);
        toast.push(t('ads.accounts.sync_queued'));
        await poll();
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    } finally {
        starting.value = false;
    }
}
const startPicked = () => startSync(syncScope.value === 'all' ? null : syncPicked.value);
const syncOne = (a: AdAccountRow) => startSync([a.id]);

async function poll(): Promise<void> {
    const job = tracked.value;
    if (!job || status.value?.finished || stalled.value) return;
    try {
        const { data } = await api.get<AdSyncStatusResponse>('/ads/accounts/sync-status', {
            params: { accounts: job.ids.join(','), since: job.since },
            silent: true,
        });
        if (tracked.value !== job) return;
        status.value = data;
        const signature = data.accounts.map((a) => `${a.id}:${a.state}`).join(',');
        if (signature !== lastSignature) {
            lastSignature = signature;
            lastChangeAt = Date.now();
        } else if (!data.finished && Date.now() - lastChangeAt >= STALL_MS) {
            stalled.value = true;
        }
        if (data.finished) {
            const failed = data.accounts.filter((a) => a.state === 'error').length;
            toast.push(failed ? t('ads.accounts.sync_done_errors', { n: n(failed) }) : t('ads.accounts.sync_done'), failed ? 'error' : 'success');
            router.reload({ only: ['connections', 'summary', 'sync_resume'] });
        }
    } catch {
        // A missed poll is retried on the next tick.
    }
}
useVisiblePoll(() => void poll(), 3000);

// Syncs this user started that are still going when the page opens resume in the bar, from the server's clock.
onMounted(() => {
    if (props.sync_resume.accounts.length) {
        track(props.sync_resume.since, [...props.sync_resume.accounts]);
        void poll();
    }
});

const STATE_TONE: Record<AdSyncState, 'neutral' | 'info' | 'positive' | 'negative' | 'warning'> = {
    queued: 'neutral',
    running: 'info',
    retrying: 'warning',
    done: 'positive',
    error: 'negative',
    skipped: 'neutral',
};
const stateOf = (id: number): AdSyncState | null => {
    const row = status.value?.accounts.find((a) => a.id === id);
    if (row) return row.state;
    return tracked.value?.ids.includes(id) && syncRunning.value ? 'queued' : null;
};
const isSyncing = (id: number) => {
    const s = stateOf(id);
    return (s === 'queued' || s === 'running' || s === 'retrying') && syncRunning.value;
};
const progressDone = computed(() => status.value?.done ?? 0);
const progressTotal = computed(() => status.value?.total ?? tracked.value?.ids.length ?? 0);
function dismissProgress(): void {
    tracked.value = null;
    status.value = null;
    stalled.value = false;
    connectionErrors.value = [];
}
/** Why a row cannot sync, as its icon's tooltip. */
function rowSyncLabel(a: AdAccountRow): string {
    if (!a.is_active) return t('ads.accounts.sync_blocked_paused');
    if (!canSyncId(a.id)) return t('ads.accounts.sync_blocked_connection');
    return t('ads.accounts.sync_account_named', { name: a.name });
}

/* ---------------- actions without a form of their own ---------------- */
const action = useForm({});
const busyKey = ref<string | null>(null);
const busy = (key: string) => busyKey.value === key;
type Method = 'post' | 'patch' | 'delete';
function run(method: Method, url: string, key: string, okMessage: string, data: Record<string, unknown> = {}, after?: () => void): void {
    busyKey.value = key;
    action
        .transform(() => data)
        [method](url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.push(okMessage);
                after?.();
            },
            onError: (errors) => toast.push(String(errors.connection ?? Object.values(errors)[0] ?? t('common.error')), 'error'),
            onFinish: () => {
                busyKey.value = null;
            },
        });
}
const testConnection = (c: AdConnectionRow) => run('post', `/ads/connections/${c.id}/test`, `test-${c.id}`, t('ads.accounts.test_ok'));
const toggleAccount = (a: AdAccountRow) =>
    run('patch', `/ads/accounts/${a.id}`, `toggle-${a.id}`, t('ads.accounts.saved'), { is_active: !a.is_active });

/* ---------------- connect / edit dialog ---------------- */
const dialogOpen = ref(false);
const editing = ref<AdConnectionRow | null>(null);
const connForm = useForm<{ platform: string; name: string; credentials: Record<string, string> }>({ platform: 'meta', name: '', credentials: {} });
const platformOf = (value: string): AdPlatformDefinition | undefined => props.platforms.find((p) => p.value === value);
const formPlatform = computed(() => platformOf(connForm.platform));
const errorOf = (key: string): string | undefined => (connForm.errors as Record<string, string | undefined>)[key];
const inputClass =
    'flex h-9 w-full rounded-md border border-input bg-card px-3 text-sm placeholder:text-muted-foreground focus-visible:border-primary focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-primary disabled:opacity-50';

function openConnect(): void {
    editing.value = null;
    connForm.defaults({ platform: 'meta', name: '', credentials: {} }).reset();
    connForm.clearErrors();
    dialogOpen.value = true;
}
function openEdit(c: AdConnectionRow): void {
    editing.value = c;
    connForm.defaults({ platform: c.platform, name: c.name, credentials: {} }).reset();
    connForm.clearErrors();
    dialogOpen.value = true;
}
const connectionOfAccount = (a: AdAccountRow) => props.connections.find((c) => c.accounts.some((x) => x.id === a.id)) ?? null;
function submitConnection(): void {
    const done = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
            toast.push(t(editing.value ? 'ads.accounts.saved' : 'ads.accounts.connected'));
        },
    };
    if (editing.value) connForm.put(`/ads/connections/${editing.value.id}`, done);
    else connForm.post('/ads/connections', done);
}
const helpKey = (platform: string) => `ads.accounts.help_${platform}`;
const fieldPlaceholder = (key: string): string | undefined => (editing.value?.configured[key] ? t('ads.accounts.secret_kept') : undefined);
const dialogError = computed(() => {
    const messages = Object.entries(connForm.errors)
        .filter(([key]) => key === 'credentials' || key.startsWith('credentials.'))
        .map(([, message]) => message);
    return messages.length ? messages.join(' ') : null;
});

/* ---------------- delete confirmation ---------------- */
const deleting = ref<AdConnectionRow | null>(null);
const deleteOpen = computed({ get: () => deleting.value !== null, set: (v) => !v && (deleting.value = null) });
function confirmDelete(): void {
    const c = deleting.value;
    if (!c) return;
    run('delete', `/ads/connections/${c.id}`, `delete-${c.id}`, t('ads.accounts.deleted'), {}, () => (deleting.value = null));
}

/* ---------------- connection chips ---------------- */
function statusChip(c: AdConnectionRow): { label: string; tone: 'positive' | 'warning' | 'negative' | 'neutral' } {
    if (c.status === 'connected') return { label: t('ads.accounts.connection_ok'), tone: 'positive' };
    if (c.status === 'pending') return { label: t('ads.accounts.connection_pending'), tone: 'warning' };
    if (c.status === 'error') return { label: t('ads.accounts.connection_error'), tone: 'negative' };
    if (c.status === 'needs_reconnect') return { label: t('ads.accounts.connection_needs_reconnect'), tone: 'negative' };
    return { label: t('ads.accounts.connection_disabled'), tone: 'neutral' };
}
const tokenDate = (iso: string | null): string => (iso ? formatDayLong(iso.slice(0, 10), locale.value) : t('ads.accounts.token_never'));
const accountStatusLabel = (s: string | null) => adAccountStatusLabel(s, t);

/* ---------------- the accounts table ---------------- */
interface AccountTableRow extends AdAccountRow {
    connection: string;
}
const rows = computed<AccountTableRow[]>(() => props.connections.flatMap((c) => c.accounts.map((a) => ({ ...a, connection: c.name }))));
const columns = computed<Column[]>(() => [
    { key: 'name', label: t('ads.accounts.col_name'), primary: true },
    { key: 'platform', label: t('ads.accounts.col_platform'), hideOnMobile: true },
    { key: 'currency', label: t('ads.accounts.col_currency'), hideOnMobile: true },
    { key: 'owner', label: t('ads.accounts.col_owner') },
    { key: 'status', label: t('ads.accounts.col_status') },
    { key: 'spend', label: t('ads.accounts.col_spend'), numeric: true },
    { key: 'last_synced_at', label: t('ads.accounts.col_last_sync'), hideOnMobile: true },
    { key: 'actions', label: t('ads.accounts.col_actions'), align: 'end' },
]);

/* buyer assignment: the select inline; the date + save show only while there is something to save */
interface Draft {
    buyer: string;
    date: string;
    base: string;
    baseDate: string;
}
const drafts = reactive<Record<number, Draft>>({});
const dateOpen = reactive<Record<number, boolean>>({});
const today = () => cairoToday();
watch(
    () => props.connections,
    (connections) => {
        for (const c of connections) {
            for (const a of c.accounts) {
                const owner = a.buyer ? String(a.buyer.id) : '';
                const start = a.buyer ? (a.history.find((p) => p.ends_on === null)?.starts_on ?? '') : '';
                const d = drafts[a.id];
                if (!d || d.base !== owner || d.baseDate !== start) {
                    drafts[a.id] = { buyer: owner, date: start || today(), base: owner, baseDate: start };
                    dateOpen[a.id] = false;
                }
            }
        }
    },
    { immediate: true },
);
const assignForm = useForm<{ media_buyer_id: number | null; starts_on: string }>({ media_buyer_id: null, starts_on: '' });
const assigningId = ref<number | null>(null);
const assignError = (a: AdAccountRow): string | null =>
    assigningId.value === a.id ? (assignForm.errors.starts_on ?? assignForm.errors.media_buyer_id ?? null) : null;
function onOwnerChange(a: AdAccountRow): void {
    const d = drafts[a.id];
    if (!d) return;
    if (d.buyer === d.base) d.date = d.baseDate || today();
    else if (d.date === d.baseDate) d.date = today();
}
const correctingStart = (a: AdAccountRow) => {
    const d = drafts[a.id];
    return d !== undefined && d.buyer === d.base && d.base !== '' && d.date !== '' && d.date !== d.baseDate;
};
const ownerChanged = (a: AdAccountRow) => drafts[a.id] !== undefined && drafts[a.id].buyer !== drafts[a.id].base;
const canAssign = (a: AdAccountRow) => ownerChanged(a) || correctingStart(a);
const editingAssignment = (a: AdAccountRow) => ownerChanged(a) || dateOpen[a.id] === true;
function cancelAssignment(a: AdAccountRow): void {
    const d = drafts[a.id];
    if (d) Object.assign(d, { buyer: d.base, date: d.baseDate || today() });
    dateOpen[a.id] = false;
    assignForm.clearErrors();
}
function assign(a: AdAccountRow): void {
    const d = drafts[a.id];
    if (!d || !canAssign(a)) return;
    assigningId.value = a.id;
    assignForm.media_buyer_id = d.buyer === '' ? null : Number(d.buyer);
    assignForm.starts_on = d.date || today();
    assignForm.clearErrors();
    assignForm.post(`/ads/accounts/${a.id}/assign`, { preserveScroll: true, onSuccess: () => toast.push(t('ads.accounts.assigned')) });
}
const ownerOptions = (a: AdAccountRow) => props.buyers.filter((b) => b.is_active || b.id === a.buyer?.id);
const assigning = (a: AdAccountRow) => assignForm.processing && assigningId.value === a.id;
const periodOwner = (name: string | null) => name ?? t('ads.accounts.unassigned');
const openStart = (a: AdAccountRow) => drafts[a.id]?.baseDate ?? '';

const crumbs = computed(() => [{ label: t('nav.ads_setup'), href: '/ads/setup' }, { label: t('ads.control.setup.accounts') }]);
const appCrumbs = computed(() => [
    { title: t('nav.ads_setup'), href: '/ads/setup' },
    { title: t('ads.control.setup.accounts'), href: '/ads/accounts' },
]);
const smallSelect = 'h-8 w-full min-w-32 rounded-md border border-input bg-card px-2 text-xs';
</script>

<template>
    <Head :title="t('ads.accounts.title')" />

    <AppLayout :breadcrumbs="appCrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
            <PageHeader :title="t('ads.accounts.title')" :description="t('ads.accounts.description')" :breadcrumbs="crumbs">
                <Button type="button" variant="outline" size="sm" data-test="connect" @click="openConnect">
                    <Plus aria-hidden="true" />{{ t('ads.accounts.connect') }}
                </Button>
                <Popover v-model:open="pickerOpen">
                    <PopoverTrigger as-child>
                        <Button type="button" size="sm" data-test="sync-open" :loading="starting" :disabled="syncRunning">
                            <RefreshCw aria-hidden="true" />{{ t('ads.accounts.sync_button') }}
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent align="end" class="w-80 max-w-[calc(100vw-2rem)] space-y-3" :collision-padding="16">
                        <p class="text-sm font-semibold">{{ t('ads.accounts.sync_picker_title') }}</p>
                        <fieldset class="space-y-1.5">
                            <legend class="sr-only">{{ t('ads.accounts.sync_picker_title') }}</legend>
                            <label class="flex items-center gap-2 text-xs">
                                <input v-model="syncScope" type="radio" value="all" name="sync-scope" data-test="sync-scope-all" />
                                {{ t('ads.accounts.sync_all', { n: n(syncOptions.length) }) }}
                            </label>
                            <label class="flex items-center gap-2 text-xs">
                                <input v-model="syncScope" type="radio" value="some" name="sync-scope" data-test="sync-scope-some" />
                                {{ t('ads.accounts.sync_some') }}
                            </label>
                        </fieldset>
                        <ul v-if="syncScope === 'some'" class="scrollbar-thin max-h-56 space-y-1 overflow-y-auto">
                            <li v-for="o in syncOptions" :key="o.id">
                                <label class="flex items-center gap-2 rounded px-1 py-1 text-xs hover:bg-muted">
                                    <input
                                        type="checkbox"
                                        :checked="syncPicked.includes(o.id)"
                                        :data-test="`sync-pick-${o.id}`"
                                        @change="toggleSyncAccount(o.id)"
                                    />
                                    <span class="min-w-0 flex-1 truncate" dir="auto">{{ o.name }}</span>
                                    <PlatformChip :platform="o.platform" size="xs" />
                                </label>
                            </li>
                        </ul>
                        <p v-if="syncScope === 'all'" class="text-2xs text-muted-foreground">{{ t('ads.accounts.sync_all_hint') }}</p>
                        <Button type="button" size="sm" class="w-full" data-test="sync-start" :disabled="!canStart" :loading="starting" @click="startPicked">
                            {{ t('ads.accounts.sync_start') }}
                        </Button>
                    </PopoverContent>
                </Popover>
            </PageHeader>
            <AdsSetupTabs />

            <!-- Filter: nothing loads until «اعرض». -->
            <section class="flex flex-wrap items-center gap-2 rounded-lg bg-card p-3 shadow-card" :aria-label="t('ads.accounts.filter_label')" data-test="filter">
                <DateRangePicker v-model="range" month />
                <Popover v-if="account_options.length > 1">
                    <PopoverTrigger
                        class="inline-flex h-9 items-center gap-1.5 rounded-md border border-input bg-background px-3 text-xs"
                        data-test="filter-accounts"
                    >
                        <ListChecks class="size-4" aria-hidden="true" />{{ accountsLabel }}<ChevronDown class="size-3.5 opacity-60" aria-hidden="true" />
                    </PopoverTrigger>
                    <PopoverContent align="start" class="w-72 max-w-[calc(100vw-2rem)] space-y-2" :collision-padding="16">
                        <button v-if="pickedFilter.length" type="button" class="text-2xs text-primary hover:underline" @click="pickedFilter = []">
                            {{ t('ads.accounts.filter_accounts_all') }}
                        </button>
                        <ul class="scrollbar-thin max-h-64 space-y-1 overflow-y-auto">
                            <li v-for="o in account_options" :key="o.id">
                                <label class="flex items-center gap-2 rounded px-1 py-1 text-xs hover:bg-muted">
                                    <input type="checkbox" :checked="pickedFilter.includes(o.id)" :data-test="`filter-pick-${o.id}`" @change="toggleFilterAccount(o.id)" />
                                    <span class="min-w-0 flex-1 truncate" dir="auto">{{ o.name }}</span>
                                    <PlatformChip :platform="o.platform" size="xs" />
                                </label>
                            </li>
                        </ul>
                    </PopoverContent>
                </Popover>
                <Button type="button" size="sm" class="h-9 ms-auto" data-test="show" :variant="filterDirty ? 'default' : 'outline'" :loading="loadingView" @click="show">
                    {{ t('ads.accounts.show') }}
                </Button>
                <p class="basis-full text-2xs text-muted-foreground">{{ rangeLabel }} · {{ filters.accounts.length ? t('ads.accounts.filter_accounts_n', { n: n(filters.accounts.length), total: n(account_options.length) }) : t('ads.accounts.filter_accounts_all') }}</p>
            </section>

            <!-- Summary -->
            <section class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" :aria-label="t('ads.accounts.summary')" data-test="tiles">
                <StatCard :label="t('ads.accounts.tile_accounts')" :value="summary.accounts" />
                <StatCard :label="t('ads.accounts.tile_active')" :value="summary.active" />
                <StatCard :label="t('ads.accounts.tile_spend')" :value="spendTile" :hint="t('ads.accounts.tile_spend_hint')" />
                <StatCard :label="t('ads.accounts.tile_last_sync')" :value="lastSyncTile" />
                <StatCard :label="t('ads.accounts.tile_errors')" :value="summary.errors" :tone="summary.errors ? 'negative' : 'default'" />
                <StatCard :label="t('ads.accounts.tile_link_rate')" :value="linkRateTile" :hint="linkRateHint" />
            </section>

            <!-- Syncs the schedule or a colleague started: a note, never this user's bar. -->
            <p v-if="sync_resume.others && !syncRunning" class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground" data-test="sync-others">
                <LoaderCircle class="size-3.5 animate-spin" aria-hidden="true" />{{ t('ads.accounts.sync_others', { n: n(sync_resume.others) }) }}
                <Link href="/ads/sync" class="text-primary hover:underline">{{ t('ads.accounts.sync_open_log') }}</Link>
            </p>

            <!-- One overall progress for the «سنك» -->
            <section v-if="tracked" class="space-y-3 rounded-lg bg-card p-4 shadow-card" role="status" data-test="sync-progress">
                <div class="flex items-center gap-2">
                    <LoaderCircle v-if="syncRunning" class="size-4 animate-spin text-primary" aria-hidden="true" />
                    <Hourglass v-else-if="stalled" class="size-4 text-warning" aria-hidden="true" />
                    <Check v-else class="size-4 text-success" aria-hidden="true" />
                    <p class="flex-1 text-sm font-semibold">
                        {{ syncRunning ? t('ads.accounts.sync_progress') : stalled ? t('ads.accounts.sync_stalled_title') : t('ads.accounts.sync_finished') }}
                    </p>
                    <IconAction :icon="X" :label="t('ads.accounts.sync_hide')" size="sm" data-test="sync-hide" @click="dismissProgress" />
                </div>
                <ProgressBar :value="progressDone" :max="progressTotal" :unit="t('ads.accounts.sync_unit')" />
                <p v-if="stalled" class="text-xs text-muted-foreground" data-test="sync-stalled">
                    {{ t('ads.accounts.sync_stalled') }}
                    <Link href="/ads/sync" class="text-primary hover:underline">{{ t('ads.accounts.sync_open_log') }}</Link>
                </p>
                <ul v-if="status" class="flex flex-wrap gap-1.5">
                    <li v-for="a in status.accounts" :key="a.id" :title="a.error ?? undefined" :data-test="`sync-chip-${a.id}`">
                        <StatusChip :label="`${a.name} · ${t(`ads.accounts.sync_state_${a.state}`)}`" :tone="STATE_TONE[a.state]" dot />
                    </li>
                </ul>
                <ul v-if="connectionErrors.length" class="space-y-1">
                    <li v-for="e in connectionErrors" :key="e.connection" role="alert" class="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">
                        {{ t('ads.accounts.sync_connection_error', { connection: e.connection, message: e.message }) }}
                    </li>
                </ul>
            </section>

            <!-- Connections -->
            <section class="space-y-3" :aria-label="t('ads.accounts.connections_title')">
                <h2 class="text-sm font-semibold">{{ t('ads.accounts.connections_title') }}</h2>
                <div v-if="!connections.length" class="rounded-lg bg-card shadow-card">
                    <EmptyState :icon="Plug" :title="t('ads.accounts.no_connections')" :body="t('ads.accounts.no_connections_body')" />
                </div>
                <ul v-else class="grid gap-3 md:grid-cols-2">
                    <li v-for="c in connections" :key="c.id" class="space-y-2 rounded-lg bg-card p-4 shadow-card" :data-test="`connection-${c.id}`">
                        <div class="flex flex-wrap items-center gap-2">
                            <PlatformChip :platform="c.platform" size="xs" />
                            <h3 class="min-w-0 flex-1 truncate text-sm font-semibold" dir="auto">{{ c.name }}</h3>
                            <StatusChip :label="statusChip(c).label" :tone="statusChip(c).tone" dot />
                            <StatusChip v-if="c.driver === 'fake'" :label="t('ads.accounts.fake')" tone="info" />
                            <div class="flex items-center gap-0.5">
                                <IconAction :icon="ShieldCheck" :label="t('ads.accounts.test')" :loading="busy(`test-${c.id}`)" @click="testConnection(c)" />
                                <IconAction :icon="Pencil" :label="t('ads.accounts.edit_connection')" @click="openEdit(c)" />
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <IconAction :icon="MoreHorizontal" :label="t('ads.accounts.more')" />
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem class="text-destructive" @select="deleting = c">
                                            <Trash2 class="size-4" aria-hidden="true" />{{ t('ads.accounts.delete') }}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ t('ads.accounts.last_sync') }}:
                            <RelativeTime v-if="c.last_synced_at" :iso="c.last_synced_at" />
                            <span v-else>{{ t('ads.accounts.never_synced') }}</span>
                            · {{ t('ads.accounts.accounts_n', { n: n(c.accounts.length) }) }}
                        </p>
                        <p v-if="c.credentials_unreadable" role="alert" class="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">
                            {{ t('ads.accounts.credentials_unreadable') }}
                        </p>
                        <p v-if="c.read_only" class="rounded-md bg-muted px-3 py-2 text-xs">
                            <StatusChip :label="t('ads.accounts.read_only')" tone="warning" class="me-1" />{{ t('ads.accounts.read_only_hint') }}
                        </p>
                        <p v-if="c.platform === 'meta' && !c.credentials_unreadable" class="text-2xs text-muted-foreground">
                            {{ t('ads.accounts.token_health') }}:
                            <template v-if="c.token_health.checked_at">
                                {{
                                    c.token_health.valid === false
                                        ? t('ads.accounts.token_invalid')
                                        : c.token_health.valid === true
                                          ? t('ads.accounts.token_valid')
                                          : t('ads.accounts.token_unverified')
                                }}
                                · {{ t('ads.accounts.token_scopes') }}: <span dir="ltr">{{ c.token_health.scopes.join(', ') || '—' }}</span>
                                · {{ t('ads.accounts.token_expires') }}: {{ tokenDate(c.token_health.expires_at) }}
                                · {{ t('ads.accounts.token_data_access') }}: {{ tokenDate(c.token_health.data_access_expires_at) }}
                            </template>
                            <template v-else>{{ t('ads.accounts.token_unchecked') }}</template>
                        </p>
                        <p v-if="c.last_error" role="alert" class="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">{{ c.last_error }}</p>
                    </li>
                </ul>
            </section>

            <!-- Accounts -->
            <section v-if="connections.length" class="space-y-3" :aria-label="t('ads.accounts.accounts_section')">
                <h2 class="text-sm font-semibold">{{ t('ads.accounts.accounts_section') }}</h2>
                <DataTable
                    table-id="ads-accounts"
                    :columns="columns"
                    :rows="rows"
                    :loading="loadingView"
                    mobile="scroll"
                    :caption="t('ads.accounts.accounts_section')"
                    :empty="t('ads.accounts.no_accounts')"
                    :row-class="(r) => (r.is_active ? undefined : 'opacity-70')"
                >
                    <template #cell-name="{ row }">
                        <div class="min-w-40">
                            <p class="font-medium" dir="auto">{{ row.name }}</p>
                            <p class="text-2xs text-muted-foreground">
                                <span dir="ltr" class="tabular-nums">{{ row.external_id }}</span> · <span dir="auto">{{ row.connection }}</span>
                            </p>
                        </div>
                    </template>
                    <template #cell-platform="{ row }"><PlatformChip :platform="row.platform" size="xs" /></template>
                    <template #cell-owner="{ row }">
                        <div v-if="drafts[row.id]" class="min-w-48 space-y-1" :data-test="`owner-${row.id}`">
                            <select v-model="drafts[row.id].buyer" :class="smallSelect" :aria-label="t('ads.accounts.owner_of', { name: row.name })" @change="onOwnerChange(row)">
                                <option value="">{{ t('ads.accounts.unassigned') }}</option>
                                <option v-for="b in ownerOptions(row)" :key="b.id" :value="String(b.id)">{{ b.name }}</option>
                            </select>
                            <div v-if="editingAssignment(row)" class="flex items-center gap-1">
                                <label class="sr-only" :for="`from-${row.id}`">{{ t('ads.accounts.from_date') }}</label>
                                <DateInput :id="`from-${row.id}`" v-model="drafts[row.id].date" class="h-8 w-36 rounded-md border border-input bg-card px-2 text-xs" />
                                <IconAction
                                    :icon="Check"
                                    variant="primary"
                                    size="sm"
                                    :label="correctingStart(row) ? t('ads.accounts.save_start') : t('ads.accounts.assign_save')"
                                    :disabled="!canAssign(row)"
                                    :loading="assigning(row)"
                                    :data-test="`assign-${row.id}`"
                                    @click="assign(row)"
                                />
                                <IconAction :icon="X" size="sm" :label="t('common.cancel')" @click="cancelAssignment(row)" />
                            </div>
                            <button
                                v-else-if="openStart(row)"
                                type="button"
                                class="text-2xs text-muted-foreground hover:text-foreground hover:underline"
                                :title="t('ads.accounts.change_start')"
                                @click="dateOpen[row.id] = true"
                            >
                                {{ t('ads.accounts.since', { date: formatDayLong(openStart(row), locale) }) }}
                            </button>
                            <p v-if="assignError(row)" role="alert" class="text-2xs text-destructive">{{ assignError(row) }}</p>
                        </div>
                    </template>
                    <template #cell-status="{ row }">
                        <div class="flex flex-wrap gap-1">
                            <StatusChip
                                :label="accountStatusLabel(row.status)"
                                :tone="adAccountActive(row.status) ? 'positive' : 'neutral'"
                                :title="!adAccountActive(row.status) ? t('ads.accounts.account_disabled') : undefined"
                            />
                            <StatusChip v-if="!row.is_active" :label="t('ads.accounts.paused_chip')" tone="warning" />
                            <span v-if="row.last_run?.status === 'error'" :title="row.last_run.error ?? undefined">
                                <StatusChip :label="t('ads.accounts.last_run_error')" tone="negative" />
                            </span>
                        </div>
                    </template>
                    <template #cell-spend="{ row }">{{ money(row.spend, row.currency) }}</template>
                    <template #cell-last_synced_at="{ row }">
                        <span v-if="isSyncing(row.id)" class="inline-flex items-center gap-1.5 text-xs font-medium text-primary">
                            <LoaderCircle class="size-3.5 animate-spin" aria-hidden="true" />{{ t('ads.accounts.syncing') }}
                        </span>
                        <RelativeTime v-else :iso="row.last_synced_at" />
                    </template>
                    <template #cell-actions="{ row }">
                        <div class="flex items-center justify-end gap-0.5" :data-test="`actions-${row.id}`">
                            <IconAction
                                :icon="RefreshCw"
                                :label="rowSyncLabel(row)"
                                :loading="isSyncing(row.id)"
                                :disabled="!canSyncId(row.id) || starting"
                                @click="syncOne(row)"
                            />
                            <Popover>
                                <PopoverTrigger as-child>
                                    <IconAction :icon="History" :label="t('ads.accounts.history_title')" />
                                </PopoverTrigger>
                                <PopoverContent class="w-80">
                                    <p class="mb-2 text-xs font-semibold">{{ t('ads.accounts.history_title') }}</p>
                                    <p v-if="!row.history.length" class="text-xs text-muted-foreground">{{ t('ads.accounts.history_empty') }}</p>
                                    <ol v-else class="space-y-1.5">
                                        <li v-for="(p, i) in row.history" :key="i" class="flex flex-wrap items-baseline justify-between gap-2 text-xs">
                                            <span class="font-medium">{{ periodOwner(p.buyer) }}</span>
                                            <span class="text-muted-foreground">
                                                {{ formatDayLong(p.starts_on, locale) }} –
                                                {{ p.ends_on ? formatDayLong(p.ends_on, locale) : t('ads.accounts.history_open') }}
                                            </span>
                                        </li>
                                    </ol>
                                </PopoverContent>
                            </Popover>
                            <IconAction
                                v-if="connectionOfAccount(row)"
                                :icon="Pencil"
                                :label="t('ads.accounts.edit_connection')"
                                @click="openEdit(connectionOfAccount(row)!)"
                            />
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <IconAction :icon="MoreHorizontal" :label="t('ads.accounts.more')" :loading="busy(`toggle-${row.id}`)" />
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem @select="toggleAccount(row)">
                                        <component :is="row.is_active ? Pause : Play" class="size-4" aria-hidden="true" />
                                        {{ row.is_active ? t('ads.accounts.stop_syncing') : t('ads.accounts.resume_syncing') }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem @select="router.visit(`/ads/sync?account=${row.id}`)">
                                        <History class="size-4" aria-hidden="true" />{{ t('ads.accounts.sync_log') }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </template>
                </DataTable>
            </section>
        </div>

        <FormDialog
            v-model:open="dialogOpen"
            :title="editing ? t('ads.accounts.edit_title') : t('ads.accounts.connect_title')"
            :description="formPlatform ? t(helpKey(formPlatform.value)) : undefined"
            :busy="connForm.processing"
            :error="dialogError"
            @submit="submitConnection"
        >
            <div class="space-y-1">
                <label class="text-xs font-medium" for="conn-platform">{{ t('ads.accounts.platform') }}</label>
                <select
                    id="conn-platform"
                    v-model="connForm.platform"
                    :class="inputClass"
                    :disabled="editing !== null"
                    @change="connForm.credentials = {}"
                >
                    <option v-for="p in platforms" :key="p.value" :value="p.value">{{ p.label }}</option>
                </select>
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium" for="conn-name">{{ t('ads.accounts.name') }}</label>
                <input
                    id="conn-name"
                    v-model="connForm.name"
                    type="text"
                    maxlength="120"
                    required
                    :placeholder="t('ads.accounts.name_placeholder')"
                    :class="inputClass"
                />
                <p v-if="connForm.errors.name" class="text-2xs text-destructive">{{ connForm.errors.name }}</p>
            </div>
            <div v-for="field in formPlatform?.fields ?? []" :key="`${connForm.platform}-${field.key}`" class="space-y-1">
                <label class="text-xs font-medium" :for="`cred-${field.key}`">{{ field.label }}</label>
                <input
                    :id="`cred-${field.key}`"
                    v-model="connForm.credentials[field.key]"
                    :type="field.secret ? 'password' : 'text'"
                    :autocomplete="field.secret ? 'new-password' : 'off'"
                    :placeholder="fieldPlaceholder(field.key)"
                    dir="ltr"
                    :class="inputClass"
                />
                <p v-if="errorOf(`credentials.${field.key}`)" class="text-2xs text-destructive">{{ errorOf(`credentials.${field.key}`) }}</p>
            </div>
        </FormDialog>

        <FormDialog
            v-model:open="deleteOpen"
            :title="t('ads.accounts.delete_title')"
            :description="deleting ? t('ads.accounts.delete_body', { name: deleting.name }) : undefined"
            :submit-label="t('ads.accounts.delete_confirm')"
            :busy="deleting !== null && busy(`delete-${deleting.id}`)"
            destructive
            @submit="confirmDelete"
        />
    </AppLayout>
</template>
