<script setup lang="ts">
/** Ads Hub — الحسابات الإعلانية: platform connections, their ad accounts, and who holds each account (spec §8.5). */
import PlatformChip from '@/components/ads/PlatformChip.vue';
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import RelativeTime from '@/components/crm/RelativeTime.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import ToggleSwitch from '@/components/crm/ToggleSwitch.vue';
import { buttonVariants } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { useVisiblePoll } from '@/composables/useVisiblePoll';
import AppLayout from '@/layouts/AppLayout.vue';
import { adAccountActive, adAccountStatusLabel, formatAdsMoney, formatDayLong } from '@/lib/ads';
import { cairoToday } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { AdAccountRow, AdConnectionRow, AdPlatformDefinition, AdsAccountsProps } from '@/types/ads';
import { Head, router, useForm } from '@inertiajs/vue3';
import { History, LoaderCircle, Pencil, Plug, Plus, RefreshCw, ShieldCheck, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps<AdsAccountsProps>();

const { t, locale } = useI18n();
const toast = useToast();

// While any account is syncing, refresh just that list in the background so the rows clear when it ends.
useVisiblePoll(() => {
    if (props.syncing.length) router.reload({ only: ['syncing'], async: true });
}, 10_000);

const inputClass =
    'flex h-9 w-full rounded-md border border-input bg-card px-3 text-sm placeholder:text-muted-foreground focus-visible:border-primary focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-primary disabled:opacity-50';
const smallSelect = 'h-8 w-full min-w-28 rounded-md border border-input bg-card px-2 text-xs';
const smallInput = 'h-8 w-32 rounded-md border border-input bg-card px-2 text-xs';
const outlineSm = cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'gap-1.5');

/* ---- actions without a form of their own (test, sync, toggle, delete) ---- */
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
const syncConnection = (c: AdConnectionRow) => run('post', `/ads/connections/${c.id}/sync`, `sync-${c.id}`, t('ads.accounts.sync_queued'));
const syncAccount = (a: AdAccountRow) => run('post', `/ads/accounts/${a.id}/sync`, `sync-acc-${a.id}`, t('ads.accounts.sync_queued'));
const toggleAccount = (a: AdAccountRow, value: boolean) =>
    run('patch', `/ads/accounts/${a.id}`, `toggle-${a.id}`, t('ads.accounts.saved'), { is_active: value });

/* ---- connect / edit dialog ---- */
const dialogOpen = ref(false);
const editing = ref<AdConnectionRow | null>(null);
const connForm = useForm<{ platform: string; name: string; credentials: Record<string, string> }>({ platform: 'meta', name: '', credentials: {} });
const platformOf = (value: string): AdPlatformDefinition | undefined => props.platforms.find((p) => p.value === value);
const formPlatform = computed(() => platformOf(connForm.platform));
const errorOf = (key: string): string | undefined => (connForm.errors as Record<string, string | undefined>)[key];

function openConnect(platform: string): void {
    editing.value = null;
    connForm.defaults({ platform, name: '', credentials: {} }).reset();
    connForm.clearErrors();
    dialogOpen.value = true;
}

function openEdit(c: AdConnectionRow): void {
    editing.value = c;
    connForm.defaults({ platform: c.platform, name: c.name, credentials: {} }).reset();
    connForm.clearErrors();
    dialogOpen.value = true;
}

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
/** The platform-level credentials error (a failed first sync) plus any field-level one, as one banner. */
const dialogError = computed(() => {
    const messages = Object.entries(connForm.errors)
        .filter(([key]) => key === 'credentials' || key.startsWith('credentials.'))
        .map(([, message]) => message);

    return messages.length ? messages.join(' ') : null;
});

/* ---- delete confirmation ---- */
const deleting = ref<AdConnectionRow | null>(null);
const deleteOpen = computed({ get: () => deleting.value !== null, set: (v) => !v && (deleting.value = null) });
function confirmDelete(): void {
    const c = deleting.value;
    if (!c) return;
    run('delete', `/ads/connections/${c.id}`, `delete-${c.id}`, t('ads.accounts.deleted'), {}, () => (deleting.value = null));
}

/* ---- status chips ---- */
function statusChip(c: AdConnectionRow): { label: string; tone: 'positive' | 'warning' | 'negative' | 'neutral' } {
    if (c.status === 'connected') return { label: t('ads.accounts.connection_ok'), tone: 'positive' };
    if (c.status === 'pending') return { label: t('ads.accounts.connection_pending'), tone: 'warning' };
    if (c.status === 'error') return { label: t('ads.accounts.connection_error'), tone: 'negative' };
    if (c.status === 'needs_reconnect') return { label: t('ads.accounts.connection_needs_reconnect'), tone: 'negative' };

    return { label: t('ads.accounts.connection_disabled'), tone: 'neutral' };
}

const tokenDate = (iso: string | null): string => (iso ? formatDayLong(iso.slice(0, 10), locale.value) : t('ads.accounts.token_never'));

const accountActive = adAccountActive;
const accountStatusLabel = (status: string | null) => adAccountStatusLabel(status, t);

/* ---- accounts per platform, one table each ---- */
interface AccountTableRow extends AdAccountRow {
    connection: string;
}
const connectionsOf = (platform: string) => props.connections.filter((c) => c.platform === platform);
const accountsOf = (platform: string): AccountTableRow[] =>
    connectionsOf(platform).flatMap((c) => c.accounts.map((a) => ({ ...a, connection: c.name })));

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('ads.accounts.col_name'), primary: true },
    { key: 'external_id', label: t('ads.accounts.col_external'), dir: 'ltr', hideOnMobile: true },
    { key: 'currency', label: t('ads.accounts.col_currency'), hideOnMobile: true },
    { key: 'status', label: t('ads.accounts.col_status') },
    { key: 'is_active', label: t('ads.accounts.col_active'), align: 'center' },
    { key: 'owner', label: t('ads.accounts.col_owner') },
    { key: 'spend_30d', label: t('ads.accounts.col_spend'), align: 'end' },
    { key: 'last_synced_at', label: t('ads.accounts.col_last_sync'), hideOnMobile: true },
    { key: 'actions', label: t('ads.accounts.col_actions'), align: 'end' },
]);

/* ---- assignment drafts: the select + date of each row; reset when the saved owner or start changes ---- */
interface Draft {
    buyer: string;
    date: string;
    base: string;
    /** Start of the open period ('' when unassigned): the same owner with another date corrects it. */
    baseDate: string;
}
const drafts = reactive<Record<number, Draft>>({});
const today = () => cairoToday();

watch(
    () => props.connections,
    (connections) => {
        for (const c of connections) {
            for (const a of c.accounts) {
                const owner = a.buyer ? String(a.buyer.id) : '';
                const start = a.buyer ? (a.history.find((p) => p.ends_on === null)?.starts_on ?? '') : '';
                const d = drafts[a.id];
                if (!d || d.base !== owner || d.baseDate !== start) drafts[a.id] = { buyer: owner, date: start || today(), base: owner, baseDate: start };
            }
        }
    },
    { immediate: true },
);

const assignForm = useForm<{ media_buyer_id: number | null; starts_on: string }>({ media_buyer_id: null, starts_on: '' });
const assigningId = ref<number | null>(null);
const assignError = (a: AdAccountRow): string | null =>
    assigningId.value === a.id ? (assignForm.errors.starts_on ?? assignForm.errors.media_buyer_id ?? null) : null;

function assign(a: AdAccountRow): void {
    const d = drafts[a.id];
    if (!d) return;
    assigningId.value = a.id;
    assignForm.media_buyer_id = d.buyer === '' ? null : Number(d.buyer);
    assignForm.starts_on = d.date || today();
    assignForm.clearErrors();
    assignForm.post(`/ads/accounts/${a.id}/assign`, {
        preserveScroll: true,
        onSuccess: () => toast.push(t('ads.accounts.assigned')),
    });
}

/** A hand-over defaults to today; going back to the current owner shows their start date again. */
function onOwnerChange(a: AdAccountRow): void {
    const d = drafts[a.id];
    if (!d) return;
    if (d.buyer === d.base) d.date = d.baseDate || today();
    else if (d.date === d.baseDate) d.date = today();
}
/** Same owner, other date: a correction of the open period's start. */
const correctingStart = (a: AdAccountRow) => {
    const d = drafts[a.id];
    return d !== undefined && d.buyer === d.base && d.base !== '' && d.date !== '' && d.date !== d.baseDate;
};
const canAssign = (a: AdAccountRow) => drafts[a.id] !== undefined && (drafts[a.id].buyer !== drafts[a.id].base || correctingStart(a));
/** Archived buyers are offered only to the account they still hold. */
const ownerOptions = (a: AdAccountRow) => props.buyers.filter((b) => b.is_active || b.id === a.buyer?.id);
const assigning = (a: AdAccountRow) => assignForm.processing && assigningId.value === a.id;
const periodOwner = (name: string | null) => name ?? t('ads.accounts.unassigned');
const money = (value: number, currency: string) => formatAdsMoney(value, locale.value, currency);
</script>

<template>
    <Head :title="t('ads.accounts.title')" />

    <AppLayout>
        <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
            <PageHeader :title="t('ads.accounts.title')" :description="t('ads.accounts.description')">
                <button type="button" :class="buttonVariants({ variant: 'default', size: 'sm' })" @click="openConnect('meta')">
                    <Plus aria-hidden="true" />{{ t('ads.accounts.connect') }}
                </button>
            </PageHeader>

            <section v-for="platform in platforms" :key="platform.value" class="space-y-3" :aria-label="platform.label">
                <div class="flex items-center gap-2">
                    <PlatformChip :platform="platform.value" />
                    <button type="button" :class="cn(outlineSm, 'ms-auto')" @click="openConnect(platform.value)">
                        <Plus aria-hidden="true" />{{ t('ads.accounts.connect') }}
                    </button>
                </div>

                <div v-if="!connectionsOf(platform.value).length" class="rounded-lg bg-card shadow-card">
                    <EmptyState :icon="Plug" :title="t('ads.accounts.no_connections')" :body="t('ads.accounts.no_connections_body')" />
                </div>

                <ul v-else class="grid gap-3 md:grid-cols-2">
                    <li v-for="c in connectionsOf(platform.value)" :key="c.id" class="space-y-3 rounded-lg bg-card p-4 shadow-card">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="min-w-0 flex-1 truncate text-sm font-semibold">{{ c.name }}</h2>
                            <StatusChip :label="statusChip(c).label" :tone="statusChip(c).tone" dot />
                            <StatusChip v-if="c.driver === 'fake'" :label="t('ads.accounts.fake')" tone="info" />
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ t('ads.accounts.last_sync') }}:
                            <RelativeTime v-if="c.last_synced_at" :iso="c.last_synced_at" />
                            <span v-else>{{ t('ads.accounts.never_synced') }}</span>
                        </p>
                        <p v-if="c.credentials_unreadable" role="alert" class="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">
                            {{ t('ads.accounts.credentials_unreadable') }}
                        </p>
                        <p v-if="c.read_only" class="rounded-md bg-muted px-3 py-2 text-xs">
                            <StatusChip :label="t('ads.accounts.read_only')" tone="warning" class="me-1" />{{ t('ads.accounts.read_only_hint') }}
                        </p>
                        <p v-if="c.platform === 'meta' && !c.credentials_unreadable" class="text-xs text-muted-foreground">
                            {{ t('ads.accounts.token_health') }}:
                            <template v-if="c.token_health.checked_at">
                                {{ c.token_health.valid === false ? t('ads.accounts.token_invalid') : t('ads.accounts.token_valid') }}
                                · {{ t('ads.accounts.token_scopes') }}: {{ c.token_health.scopes.join(', ') || '-' }}
                                · {{ t('ads.accounts.token_expires') }}: {{ tokenDate(c.token_health.expires_at) }}
                                · {{ t('ads.accounts.token_data_access') }}: {{ tokenDate(c.token_health.data_access_expires_at) }}
                            </template>
                            <template v-else>{{ t('ads.accounts.token_unchecked') }}</template>
                        </p>
                        <p v-if="c.last_error" role="alert" class="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">
                            {{ c.last_error }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" :class="outlineSm" :disabled="busy(`test-${c.id}`)" @click="testConnection(c)">
                                <LoaderCircle v-if="busy(`test-${c.id}`)" class="animate-spin" aria-hidden="true" />
                                <ShieldCheck v-else aria-hidden="true" />{{ t('ads.accounts.test') }}
                            </button>
                            <button type="button" :class="outlineSm" :disabled="busy(`sync-${c.id}`)" @click="syncConnection(c)">
                                <LoaderCircle v-if="busy(`sync-${c.id}`)" class="animate-spin" aria-hidden="true" />
                                <RefreshCw v-else aria-hidden="true" />{{ t('ads.accounts.sync') }}
                            </button>
                            <button type="button" :class="outlineSm" @click="openEdit(c)">
                                <Pencil aria-hidden="true" />{{ t('ads.accounts.edit') }}
                            </button>
                            <button type="button" :class="cn(outlineSm, 'text-destructive')" @click="deleting = c">
                                <Trash2 aria-hidden="true" />{{ t('ads.accounts.delete') }}
                            </button>
                        </div>
                    </li>
                </ul>

                <template v-if="connectionsOf(platform.value).length">
                    <h3 class="pt-1 text-sm font-semibold">{{ t('ads.accounts.accounts_title', { platform: platform.label }) }}</h3>
                    <DataTable
                        :columns="columns"
                        :rows="accountsOf(platform.value)"
                        mobile="scroll"
                        :caption="t('ads.accounts.accounts_title', { platform: platform.label })"
                        :empty="t('ads.accounts.no_accounts')"
                    >
                        <template #cell-name="{ row }">
                            <div class="min-w-40">
                                <p class="font-medium">{{ row.name }}</p>
                                <p class="text-2xs text-muted-foreground">{{ row.connection }}</p>
                            </div>
                        </template>
                        <template #cell-status="{ row }">
                            <StatusChip
                                :label="accountStatusLabel(row.status)"
                                :tone="accountActive(row.status) ? 'positive' : 'neutral'"
                                :title="!accountActive(row.status) ? t('ads.accounts.account_disabled') : undefined"
                            />
                        </template>
                        <template #cell-is_active="{ row }">
                            <ToggleSwitch
                                :model-value="row.is_active"
                                :label="t('ads.accounts.active_label', { name: row.name })"
                                :disabled="busy(`toggle-${row.id}`)"
                                @update:model-value="toggleAccount(row, $event)"
                            />
                        </template>
                        <template #cell-owner="{ row }">
                            <div v-if="drafts[row.id]" class="min-w-56 space-y-1">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <select
                                        v-model="drafts[row.id].buyer"
                                        :class="smallSelect"
                                        :aria-label="t('ads.accounts.col_owner')"
                                        @change="onOwnerChange(row)"
                                    >
                                        <option value="">{{ t('ads.accounts.unassigned') }}</option>
                                        <option v-for="b in ownerOptions(row)" :key="b.id" :value="String(b.id)">{{ b.name }}</option>
                                    </select>
                                </div>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <label class="text-2xs text-muted-foreground" :for="`from-${row.id}`">{{ t('ads.accounts.from_date') }}</label>
                                    <input :id="`from-${row.id}`" v-model="drafts[row.id].date" type="date" dir="ltr" :class="smallInput" />
                                    <button
                                        type="button"
                                        :class="buttonVariants({ variant: 'default', size: 'sm' })"
                                        :disabled="!canAssign(row) || assigning(row)"
                                        @click="assign(row)"
                                    >
                                        <LoaderCircle v-if="assigning(row)" class="animate-spin" aria-hidden="true" />{{
                                            correctingStart(row) ? t('ads.accounts.save_start') : t('ads.accounts.assign_save')
                                        }}
                                    </button>
                                    <Popover>
                                        <PopoverTrigger as-child>
                                            <button
                                                type="button"
                                                :class="buttonVariants({ variant: 'ghost', size: 'sm' })"
                                                :aria-label="t('ads.accounts.history_title')"
                                            >
                                                <History aria-hidden="true" />{{ t('ads.accounts.history') }}
                                            </button>
                                        </PopoverTrigger>
                                        <PopoverContent class="w-80">
                                            <p class="mb-2 text-xs font-semibold">{{ t('ads.accounts.history_title') }}</p>
                                            <p v-if="!row.history.length" class="text-xs text-muted-foreground">
                                                {{ t('ads.accounts.history_empty') }}
                                            </p>
                                            <ol v-else class="space-y-1.5">
                                                <li
                                                    v-for="(p, i) in row.history"
                                                    :key="i"
                                                    class="flex flex-wrap items-baseline justify-between gap-2 text-xs"
                                                >
                                                    <span class="font-medium">{{ periodOwner(p.buyer) }}</span>
                                                    <span class="text-muted-foreground">
                                                        {{ formatDayLong(p.starts_on, locale) }} –
                                                        {{ p.ends_on ? formatDayLong(p.ends_on, locale) : t('ads.accounts.history_open') }}
                                                    </span>
                                                </li>
                                            </ol>
                                        </PopoverContent>
                                    </Popover>
                                </div>
                                <p v-if="assignError(row)" role="alert" class="text-2xs text-destructive">{{ assignError(row) }}</p>
                            </div>
                        </template>
                        <template #cell-spend_30d="{ row }">{{ money(row.spend_30d, row.currency) }}</template>
                        <template #cell-last_synced_at="{ row }">
                            <span v-if="props.syncing.includes(row.id)" class="inline-flex items-center gap-1.5 text-xs font-medium text-primary" role="status">
                                <LoaderCircle class="size-3.5 animate-spin" aria-hidden="true" />{{ t('ads.accounts.syncing') }}
                            </span>
                            <RelativeTime v-else :iso="row.last_synced_at" />
                        </template>
                        <template #cell-actions="{ row }">
                            <button
                                type="button"
                                :class="outlineSm"
                                :disabled="busy(`sync-acc-${row.id}`)"
                                :title="t('ads.accounts.sync_account')"
                                @click="syncAccount(row)"
                            >
                                <LoaderCircle v-if="busy(`sync-acc-${row.id}`)" class="animate-spin" aria-hidden="true" />
                                <RefreshCw v-else aria-hidden="true" /><span class="sr-only 2xl:not-sr-only">{{
                                    t('ads.accounts.sync_account')
                                }}</span>
                            </button>
                        </template>
                    </DataTable>
                </template>
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
