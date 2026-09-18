<script setup lang="ts">
import ChannelCard from '@/components/crm/ChannelCard.vue';
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import type { SharedData } from '@/types';
import type { ChannelAccount, ChannelTestResult, FailedWebhookEvent } from '@/types/admin';
import { Head, router, usePage } from '@inertiajs/vue3';
import { LoaderCircle, RotateCw } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{ accounts: ChannelAccount[]; failedEvents: FailedWebhookEvent[] }>();

const { t, locale } = useI18n();
const page = usePage<SharedData>();
const api = useApi();
const toast = useToast();

const busyAccount = ref<number | null>(null);
const busyEvent = ref<number | null>(null);
const testingAccount = ref<number | null>(null);
const subscribingAccount = ref<number | null>(null);
const testResults = reactive<Record<number, ChannelTestResult | null>>({});

// One card per platform: the first connected account, else the first one on file.
const cards = computed(() =>
    (page.props.platforms ?? []).map((p) => {
        const list = props.accounts.filter((a) => a.platform === p.value);
        return { platform: p.value, account: list.find((a) => a.status !== 'disconnected') ?? list[0] ?? null };
    }),
);

// The Instagram card's "linked Facebook page" picker only ever offers Facebook accounts.
const facebookAccounts = computed(() => props.accounts.filter((a) => a.platform === 'facebook').map((a) => ({ id: a.id, name: a.name })));

async function changeDriver(account: ChannelAccount, driver: 'fake' | 'live'): Promise<void> {
    busyAccount.value = account.id;
    try {
        await api.put(`/settings/channels/${account.id}`, { driver });
        toast.push(t('ui.saved'));
        router.reload({ only: ['accounts'] });
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        busyAccount.value = null;
    }
}

async function saveLive(account: ChannelAccount, payload: { external_id: string; credentials: Record<string, unknown> }): Promise<void> {
    busyAccount.value = account.id;
    try {
        await api.put(`/settings/channels/${account.id}`, payload);
        toast.push(t('ui.saved'));
        router.reload({ only: ['accounts'] });
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        busyAccount.value = null;
    }
}

// The test/subscribe endpoints answer a misconfigured account (e.g. an Instagram card
// with no linked Facebook page) with HTTP 422 and a plain {ok:false, error} body rather
// than 200 — axios throws for that, so both success and failure responses are read the
// same way via this helper instead of duplicating the {ok, error} extraction twice.
function resultFrom(error: unknown): ChannelTestResult {
    if (error && typeof error === 'object' && 'response' in error) {
        const data = (error as { response?: { data?: ChannelTestResult } }).response?.data;
        if (data && typeof data === 'object' && 'ok' in data) return data;
    }
    return { ok: false, error: apiErrorMessage(error, t('common.error')) };
}

async function testAccount(account: ChannelAccount): Promise<void> {
    testingAccount.value = account.id;
    testResults[account.id] = null;
    try {
        const { data } = await api.post<ChannelTestResult>(`/settings/channels/${account.id}/test`);
        testResults[account.id] = data;
        toast.push(data.ok ? t('settings.channels.test_ok', { name: data.page_name ?? data.ig_username ?? '—' }) : (data.error ?? t('common.error')), data.ok ? 'success' : 'error');
    } catch (error) {
        const data = resultFrom(error);
        testResults[account.id] = data;
        toast.push(data.error ?? t('common.error'), 'error');
    } finally {
        testingAccount.value = null;
    }
}

async function subscribeAccount(account: ChannelAccount): Promise<void> {
    subscribingAccount.value = account.id;
    try {
        const { data } = await api.post<ChannelTestResult>(`/settings/channels/${account.id}/subscribe`);
        testResults[account.id] = data;
        toast.push(data.ok ? t('settings.channels.subscribe_ok') : (data.error ?? t('common.error')), data.ok ? 'success' : 'error');
    } catch (error) {
        const data = resultFrom(error);
        testResults[account.id] = data;
        toast.push(data.error ?? t('common.error'), 'error');
    } finally {
        subscribingAccount.value = null;
    }
}

async function reprocess(event: FailedWebhookEvent): Promise<void> {
    busyEvent.value = event.id;
    try {
        await api.post(`/settings/channels/webhook-events/${event.id}/reprocess`);
        toast.push(t('settings.channels.reprocessed'));
        router.reload({ only: ['failedEvents', 'accounts'] });
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        busyEvent.value = null;
    }
}

const columns = computed<Column[]>(() => [
    { key: 'event', label: t('settings.channels.event') },
    { key: 'attempts', label: t('settings.channels.attempts'), align: 'end' },
    { key: 'error', label: t('settings.channels.error') },
    { key: 'created_at', label: t('orders.columns.date') },
    { key: 'actions', label: t('ui.actions'), align: 'end' },
]);

const breadcrumbs = computed(() => [{ title: t('settings.channels.title'), href: '/settings/channels' }]);
</script>

<template>
    <Head :title="t('settings.channels.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('settings.channels.title')" :description="t('settings.channels.description')" />

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <ChannelCard
                    v-for="card in cards"
                    :key="card.platform"
                    :platform="card.platform"
                    :account="card.account"
                    :busy="busyAccount === card.account?.id"
                    :testing="testingAccount === card.account?.id"
                    :subscribing="subscribingAccount === card.account?.id"
                    :test-result="card.account ? (testResults[card.account.id] ?? null) : null"
                    :facebook-accounts="facebookAccounts"
                    @driver="changeDriver"
                    @save-live="saveLive"
                    @test="testAccount"
                    @subscribe="subscribeAccount"
                />
            </div>

            <section class="space-y-2">
                <h2 class="text-sm font-medium">{{ t('settings.channels.failed_events') }}</h2>
                <DataTable :columns="columns" :rows="failedEvents" :empty="t('settings.channels.no_failed')">
                    <template #cell-event="{ row }">
                        <span class="font-medium" dir="ltr">#{{ row.id }} · {{ row.provider }} · {{ row.event_type ?? '—' }}</span>
                    </template>
                    <template #cell-attempts="{ row }"><span class="tabular-nums">{{ row.attempts }}</span></template>
                    <template #cell-error="{ row }"><span class="line-clamp-2 max-w-md break-words text-destructive" dir="ltr">{{ row.error ?? '—' }}</span></template>
                    <template #cell-created_at="{ row }"><span class="whitespace-nowrap tabular-nums text-muted-foreground">{{ formatDateTime(row.created_at, locale) }}</span></template>
                    <template #cell-actions="{ row }">
                        <button type="button" class="inline-flex h-7 items-center gap-1 rounded-md border border-border px-2 hover:bg-muted disabled:opacity-50" :disabled="busyEvent !== null" @click="reprocess(row)">
                            <LoaderCircle v-if="busyEvent === row.id" class="size-3 animate-spin" aria-hidden="true" />
                            <RotateCw v-else class="size-3" aria-hidden="true" />{{ t('settings.channels.reprocess') }}
                        </button>
                    </template>
                </DataTable>
            </section>
        </div>
    </AppLayout>
</template>
