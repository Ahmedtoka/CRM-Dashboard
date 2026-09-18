<script setup lang="ts">
import ConnectForm from '@/components/crm/shopify/ConnectForm.vue';
import ConnectGuide from '@/components/crm/shopify/ConnectGuide.vue';
import ImportProgress from '@/components/crm/shopify/ImportProgress.vue';
import ShopifySettingsForm from '@/components/crm/shopify/ShopifySettingsForm.vue';
import StatusCard from '@/components/crm/shopify/StatusCard.vue';
import SyncLog from '@/components/crm/shopify/SyncLog.vue';
import WebhookTable from '@/components/crm/shopify/WebhookTable.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useEcho } from '@/composables/useEcho';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import type {
    ShopifyImportState,
    ShopifyIntegrationRow,
    ShopifyLastSync,
    ShopifyStatusResponse,
    ShopifySyncResource,
    ShopifySyncRunRow,
    ShopifyTestResult,
    ShopifyWebhookRow,
} from '@/types/admin';
import { Head } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { computed, onBeforeUnmount, reactive, ref } from 'vue';

const props = defineProps<{
    integration: ShopifyIntegrationRow | null;
    requiredScopes: string[];
    webhooks: ShopifyWebhookRow[];
    runs: ShopifySyncRunRow[];
    lastSync: ShopifyLastSync;
}>();

const { t, locale } = useI18n();
const api = useApi();
const toast = useToast();
const { echo, live, poll } = useEcho();

const integration = ref<ShopifyIntegrationRow | null>(props.integration);
const webhooks = ref<ShopifyWebhookRow[]>(props.webhooks);
const runs = ref<ShopifySyncRunRow[]>(props.runs);
const lastSync = ref<ShopifyLastSync>(props.lastSync);

const isConnected = computed(() => integration.value?.status === 'connected');
const needsCredentials = computed(() => integration.value === null || integration.value.status !== 'connected');

async function refreshStatus(): Promise<void> {
    const { data } = await api.get<ShopifyStatusResponse>('/settings/shopify/status', { silent: true });
    integration.value = data.integration;
    runs.value = data.runs;
    webhooks.value = data.webhooks;
}

// --- test / connect ---
const testing = ref(false);
const connecting = ref(false);
const testResult = ref<ShopifyTestResult | null>(null);

function errorPayload(error: unknown): ShopifyTestResult {
    const data = (error as { response?: { data?: ShopifyTestResult } })?.response?.data;
    if (data && typeof data === 'object' && 'ok' in data) return data;
    return { ok: false, error: apiErrorMessage(error, t('common.error')) };
}

async function testConnection(payload: { shop_domain: string; access_token: string }): Promise<void> {
    testing.value = true;
    testResult.value = null;
    try {
        const { data } = await api.post<ShopifyTestResult>('/settings/shopify/test', payload);
        testResult.value = data;
    } catch (error) {
        testResult.value = errorPayload(error);
    } finally {
        testing.value = false;
    }
}

async function connect(payload: { shop_domain: string; access_token: string; api_secret: string | null }): Promise<void> {
    connecting.value = true;
    try {
        await api.post('/settings/shopify/connect', payload);
        toast.push(t('ui.saved'));
        testResult.value = null;
        await refreshStatus();
    } catch (error) {
        const data = errorPayload(error);
        testResult.value = data;
        toast.push(data.error ?? t('common.error'), 'error');
    } finally {
        connecting.value = false;
    }
}

// --- disconnect ---
const disconnecting = ref(false);

async function disconnect(): Promise<void> {
    if (!integration.value) return;
    if (!window.confirm(t('settings.shopify.card.disconnect_confirm', { name: integration.value.shop_domain }))) return;

    disconnecting.value = true;
    try {
        const { data } = await api.delete<{ data: { ok: boolean; warning: string | null } }>('/settings/shopify');
        toast.push(t('settings.shopify.card.disconnected'));
        if (data?.data?.warning) {
            toast.push(t('settings.shopify.card.disconnect_warning', { error: data.data.warning }), 'error');
        }
        await refreshStatus();
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        disconnecting.value = false;
    }
}

// --- import progress ---
const resuming = ref(false);

async function resumeImport(): Promise<void> {
    resuming.value = true;
    try {
        await api.post('/settings/shopify/resume-import');
        await refreshStatus();
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        resuming.value = false;
    }
}

// --- manual sync ---
const syncing = reactive<Record<ShopifySyncResource, boolean>>({ shipping: false, products: false, customers: false, orders: false });
const ordersFrom = ref('');
const ordersTo = ref('');

async function sync(resource: ShopifySyncResource, range?: { from: string; to: string }): Promise<void> {
    syncing[resource] = true;
    try {
        await api.post('/settings/shopify/sync', { resource, ...range });
        toast.push(t('settings.shopify.sync.queued', { resource: t(`settings.shopify.import.stage.${resource}`) }));
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        syncing[resource] = false;
    }
}

function syncOrders(): void {
    if (!ordersFrom.value || !ordersTo.value) {
        toast.push(t('settings.shopify.sync.orders_range_required'), 'error');
        return;
    }
    sync('orders', { from: ordersFrom.value, to: ordersTo.value });
}

// --- webhooks ---
const reregistering = ref(false);

async function reregisterWebhooks(): Promise<void> {
    reregistering.value = true;
    try {
        await api.post('/settings/shopify/webhooks/reregister');
        toast.push(t('settings.shopify.webhooks.reregistered'));
        await refreshStatus();
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        reregistering.value = false;
    }
}

// --- operational settings ---
const savingSettings = ref(false);

async function saveSettings(payload: ShopifyIntegrationRow['settings']): Promise<void> {
    savingSettings.value = true;
    try {
        await api.put('/settings/shopify/settings', payload);
        toast.push(t('settings.shopify.settings_form.saved'));
        await refreshStatus();
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        savingSettings.value = false;
    }
}

// --- live progress: IntegrationProgress broadcast, 5s polling fallback otherwise ---
echo?.private('integrations').listen('IntegrationProgress', (event: { import_state: ShopifyImportState }) => {
    if (integration.value) {
        integration.value = { ...integration.value, import_state: event.import_state };
    }
});
poll(refreshStatus);

onBeforeUnmount(() => echo?.leave('integrations'));

const breadcrumbs = computed(() => [{ title: t('settings.shopify.title'), href: '/settings/shopify' }]);
const syncResources: ShopifySyncResource[] = ['shipping', 'products', 'customers'];
</script>

<template>
    <Head :title="t('settings.shopify.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto grid w-full max-w-7xl gap-4 p-3 md:p-6">
            <PageHeader :title="t('settings.shopify.title')" :description="t('settings.shopify.description')" />

            <StatusCard v-if="integration" :integration="integration" :disconnecting="disconnecting" @disconnect="disconnect" />

            <template v-if="needsCredentials">
                <ConnectGuide :required-scopes="requiredScopes" />
                <ConnectForm :testing="testing" :connecting="connecting" :test-result="testResult" :reconnect="integration !== null" @test="testConnection" @connect="connect" />
            </template>

            <template v-else-if="integration">
                <ImportProgress :import-state="integration.import_state" :live="live" :resuming="resuming" @resume="resumeImport" />

                <section class="grid gap-3 rounded-lg bg-card p-4 text-xs shadow-card">
                    <h2 class="text-sm font-semibold">{{ t('settings.shopify.sync.title') }}</h2>

                    <ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        <li v-for="resource in syncResources" :key="resource" class="grid gap-1.5 rounded-md border border-border p-2.5">
                            <span class="font-medium">{{ t(`settings.shopify.import.stage.${resource}`) }}</span>
                            <span class="text-muted-foreground">{{ t('settings.shopify.sync.last_sync') }}: {{ formatDateTime(lastSync[resource], locale) || t('settings.shopify.sync.never') }}</span>
                            <button
                                type="button"
                                class="inline-flex h-8 w-fit items-center gap-1.5 rounded-md border border-border px-2.5 font-medium hover:bg-muted disabled:opacity-50"
                                :disabled="syncing[resource]"
                                @click="sync(resource)"
                            >
                                <LoaderCircle v-if="syncing[resource]" class="size-3.5 animate-spin" aria-hidden="true" />
                                {{ t('settings.shopify.sync.sync_now') }}
                            </button>
                        </li>

                        <li class="grid gap-1.5 rounded-md border border-border p-2.5">
                            <span class="font-medium">{{ t('settings.shopify.import.stage.orders') }}</span>
                            <span class="text-muted-foreground">{{ t('settings.shopify.sync.last_sync') }}: {{ formatDateTime(lastSync.orders, locale) || t('settings.shopify.sync.never') }}</span>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <label class="sr-only" for="orders-from">{{ t('settings.shopify.sync.from') }}</label>
                                <input id="orders-from" v-model="ordersFrom" type="date" dir="ltr" class="h-8 rounded-md border border-input bg-background px-2" :max="ordersTo || undefined" />
                                <span class="text-muted-foreground" aria-hidden="true">→</span>
                                <label class="sr-only" for="orders-to">{{ t('settings.shopify.sync.to') }}</label>
                                <input id="orders-to" v-model="ordersTo" type="date" dir="ltr" class="h-8 rounded-md border border-input bg-background px-2" :min="ordersFrom || undefined" />
                            </div>
                            <button
                                type="button"
                                class="inline-flex h-8 w-fit items-center gap-1.5 rounded-md border border-border px-2.5 font-medium hover:bg-muted disabled:opacity-50"
                                :disabled="syncing.orders"
                                @click="syncOrders"
                            >
                                <LoaderCircle v-if="syncing.orders" class="size-3.5 animate-spin" aria-hidden="true" />
                                {{ t('settings.shopify.sync.sync_now') }}
                            </button>
                        </li>
                    </ul>
                </section>

                <WebhookTable :webhooks="webhooks" :reregistering="reregistering" @reregister="reregisterWebhooks" />

                <ShopifySettingsForm :settings="integration.settings" :busy="savingSettings" @submit="saveSettings" />
            </template>

            <SyncLog :runs="runs" />
        </div>
    </AppLayout>
</template>
