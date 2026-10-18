<script setup lang="ts">
import DateInput from '@/components/crm/DateInput.vue';
import SettingsPanel from '@/components/crm/settings/SettingsPanel.vue';
import SettingsRow from '@/components/crm/settings/SettingsRow.vue';
import SettingsShell from '@/components/crm/settings/SettingsShell.vue';
import AppConnectionCard from '@/components/crm/shopify/AppConnectionCard.vue';
import ConnectForm from '@/components/crm/shopify/ConnectForm.vue';
import ConnectGuide from '@/components/crm/shopify/ConnectGuide.vue';
import ImportProgress from '@/components/crm/shopify/ImportProgress.vue';
import LiveUpdatesCard from '@/components/crm/shopify/LiveUpdatesCard.vue';
import ShopifySettingsForm from '@/components/crm/shopify/ShopifySettingsForm.vue';
import StatusCard from '@/components/crm/shopify/StatusCard.vue';
import SyncLog from '@/components/crm/shopify/SyncLog.vue';
import WebhookTable from '@/components/crm/shopify/WebhookTable.vue';
import WebhookRejectionLine from '@/components/crm/WebhookRejectionLine.vue';
import { Button } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useEcho } from '@/composables/useEcho';
import { useI18n } from '@/composables/useI18n';
import { useSettingsBreadcrumbs } from '@/composables/useSettingsBreadcrumbs';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import { sectionTabs } from '@/lib/sectionTabs';
import type {
    ShopifyAppInfo,
    ShopifyImportState,
    ShopifyIntegrationRow,
    ShopifyLastSync,
    ShopifyStatusResponse,
    ShopifySyncResource,
    ShopifySyncRunRow,
    ShopifyTestResult,
    ShopifyWebhookRow,
    WebhookRejectionSummary,
} from '@/types/admin';
import { Head } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';
import { computed, onBeforeUnmount, reactive, ref } from 'vue';

const props = defineProps<{
    integration: ShopifyIntegrationRow | null;
    requiredScopes: string[];
    webhooks: ShopifyWebhookRow[];
    runs: ShopifySyncRunRow[];
    lastSync: ShopifyLastSync;
    /** Webhooks refused for a bad HMAC in the last day, and the last one accepted (2026-10-17). */
    webhookRejections?: WebhookRejectionSummary | null;
    lastWebhookAt?: string | null;
    /** The Partners app (round 2026-10-17). */
    app?: ShopifyAppInfo;
}>();

const { t, locale } = useI18n();
const api = useApi();
const toast = useToast();
const { echo, live, poll } = useEcho();

const integration = ref<ShopifyIntegrationRow | null>(props.integration);
const webhooks = ref<ShopifyWebhookRow[]>(props.webhooks);
const runs = ref<ShopifySyncRunRow[]>(props.runs);
const lastSync = ref<ShopifyLastSync>(props.lastSync);
/** The store's most recent sync of any resource: the page's freshness chip. */
const freshness = computed(() => (Object.values(lastSync.value).filter(Boolean) as string[]).sort().at(-1) ?? null);

// On the Partners app a lost connection comes back by opening the app in Shopify admin, never by pasting a token.
const needsCredentials = computed(
    () => (integration.value === null || integration.value.status !== 'connected') && integration.value?.auth_mode !== 'app',
);
// The Partners app panel: always once a store is connected (it says where the store stands with the app), and before
// that only when the app is configured on the server (then installing it is the way to connect).
const showAppCard = computed(() => props.app !== undefined && (integration.value !== null || props.app.configured));

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

// --- Shopify app cutover: remove the old app's webhooks / roll back to the manual connection ---
const removingLegacy = ref(false);
const rollingBack = ref(false);
const allowingApp = ref(false);

async function allowApp(): Promise<void> {
    allowingApp.value = true;
    try {
        await api.post('/settings/shopify/app/allow');
        toast.push(t('settings.shopify.app_conn.allowed'));
        await refreshStatus();
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        allowingApp.value = false;
    }
}

async function removeLegacyWebhooks(): Promise<void> {
    removingLegacy.value = true;
    try {
        const { data } = await api.post<{ data: { removed: number } }>('/settings/shopify/app/remove-legacy-webhooks');
        toast.push(t('settings.shopify.app_conn.removed', { n: data?.data?.removed ?? 0 }));
        await refreshStatus();
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        removingLegacy.value = false;
    }
}

async function rollbackToManual(): Promise<void> {
    rollingBack.value = true;
    try {
        await api.post('/settings/shopify/app/rollback');
        toast.push(t('settings.shopify.app_conn.rolled_back'));
        await refreshStatus();
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        rollingBack.value = false;
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

// --- API secret (webhook signatures) ---
const savingSecret = ref(false);

async function saveSecret(secret: string): Promise<void> {
    savingSecret.value = true;
    try {
        await api.put('/settings/shopify/secret', { api_secret: secret });
        toast.push(t('settings.shopify.live.saved'));
        await refreshStatus();
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        savingSecret.value = false;
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

const title = computed(() => t('settings.shopify.title'));
const breadcrumbs = useSettingsBreadcrumbs(() => title.value);
const syncResources: ShopifySyncResource[] = ['shipping', 'products', 'customers'];
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsShell
            :title="title"
            :description="t('settings.shopify.description')"
            :tabs="sectionTabs.shopify(t)"
            :tabs-label="t('section_tabs.shopify.label')"
            :freshness="freshness"
        >
            <div class="grid items-start gap-5 xl:grid-cols-2">
                <StatusCard
                    v-if="integration"
                    :integration="integration"
                    :disconnecting="disconnecting"
                    :webhooks="webhooks"
                    :last-webhook-at="lastWebhookAt ?? null"
                    :reregistering="reregistering"
                    @disconnect="disconnect"
                    @reregister="reregisterWebhooks"
                />

                <AppConnectionCard
                    v-if="showAppCard"
                    :integration="integration"
                    :app="app ?? { configured: false, last_verified: {} }"
                    :removing="removingLegacy"
                    :rolling-back="rollingBack"
                    :allowing="allowingApp"
                    @remove-legacy="removeLegacyWebhooks"
                    @rollback="rollbackToManual"
                    @allow-app="allowApp"
                />
            </div>

            <template v-if="needsCredentials">
                <ConnectGuide :required-scopes="requiredScopes" />
                <ConnectForm
                    :testing="testing"
                    :connecting="connecting"
                    :test-result="testResult"
                    :reconnect="integration !== null"
                    @test="testConnection"
                    @connect="connect"
                />
            </template>

            <template v-else-if="integration">
                <section
                    v-if="webhookRejections?.count"
                    class="grid gap-1.5 rounded-lg border border-danger/30 bg-card p-4 text-13"
                    data-testid="shopify-webhook-health"
                >
                    <WebhookRejectionLine :summary="webhookRejections" secret="api_secret" />
                    <p class="text-fg-2">
                        {{ t('settings.webhook_rejections.last_success') }}:
                        <span class="tabular-nums text-foreground">{{ formatDateTime(lastWebhookAt ?? null, locale) || t('ui.never') }}</span>
                    </p>
                </section>

                <LiveUpdatesCard :has-secret="integration.has_webhook_secret" :webhooks="webhooks" :saving="savingSecret" @save-secret="saveSecret" />

                <ImportProgress :import-state="integration.import_state" :live="live" :resuming="resuming" @resume="resumeImport" />

                <SettingsPanel :title="t('settings.shopify.sync.title')">
                    <SettingsRow
                        v-for="resource in syncResources"
                        :key="resource"
                        :title="t(`settings.shopify.import.stage.${resource}`)"
                        :description="`${t('settings.shopify.sync.last_sync')}: ${formatDateTime(lastSync[resource], locale) || t('settings.shopify.sync.never')}`"
                    >
                        <Button variant="outline" size="sm" class="max-sm:h-10" type="button" :loading="syncing[resource]" @click="sync(resource)">
                            {{ t('settings.shopify.sync.sync_now') }}
                        </Button>
                    </SettingsRow>

                    <SettingsRow
                        :title="t('settings.shopify.import.stage.orders')"
                        :description="`${t('settings.shopify.sync.last_sync')}: ${formatDateTime(lastSync.orders, locale) || t('settings.shopify.sync.never')}`"
                    >
                        <template #below>
                            <div class="mt-2 flex min-w-0 flex-wrap items-center gap-1.5">
                                <label class="sr-only" for="orders-from">{{ t('settings.shopify.sync.from') }}</label>
                                <DateInput
                                    id="orders-from"
                                    v-model="ordersFrom"
                                    class="h-10 w-36 min-w-0 rounded-md border border-line-strong bg-card px-2 sm:h-9"
                                    :max="ordersTo || undefined"
                                />
                                <ArrowRight class="rtl-flip size-3.5 text-fg-3" aria-hidden="true" />
                                <label class="sr-only" for="orders-to">{{ t('settings.shopify.sync.to') }}</label>
                                <DateInput
                                    id="orders-to"
                                    v-model="ordersTo"
                                    class="h-10 w-36 min-w-0 rounded-md border border-line-strong bg-card px-2 sm:h-9"
                                    :min="ordersFrom || undefined"
                                />
                            </div>
                        </template>
                        <Button variant="outline" size="sm" class="max-sm:h-10" type="button" :loading="syncing.orders" @click="syncOrders">
                            {{ t('settings.shopify.sync.sync_now') }}
                        </Button>
                    </SettingsRow>
                </SettingsPanel>

                <WebhookTable :webhooks="webhooks" />

                <ShopifySettingsForm :settings="integration.settings" :busy="savingSettings" @submit="saveSettings" />
            </template>

            <SyncLog :runs="runs" />
        </SettingsShell>
    </AppLayout>
</template>
