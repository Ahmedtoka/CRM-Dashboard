<script setup lang="ts">
import ChannelCard from '@/components/crm/ChannelCard.vue';
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import IconAction from '@/components/crm/IconAction.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import SettingsShell from '@/components/crm/settings/SettingsShell.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useNow } from '@/composables/useNow';
import { useSettingsBreadcrumbs } from '@/composables/useSettingsBreadcrumbs';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatDateTime, formatSince } from '@/lib/format';
import { sectionTabs } from '@/lib/sectionTabs';
import type { SharedData } from '@/types';
import type { ChannelAccount, ChannelTestResult, FacebookLoginSettings, FailedWebhookEvent, WebhookRejectionSummary } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { Head, router, usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, Info, RotateCw, TriangleAlert, X } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{
    accounts: ChannelAccount[];
    failedEvents: FailedWebhookEvent[];
    facebookLogin: FacebookLoginSettings;
    /** Per platform: webhooks refused for a bad signature in the last day (2026-10-17). */
    webhookRejections?: Partial<Record<PlatformValue, WebhookRejectionSummary>>;
}>();

const { t, locale } = useI18n();
const page = usePage<SharedData>();
const api = useApi();
const toast = useToast();

const busyAccount = ref<number | null>(null);
const busyEvent = ref<number | null>(null);
const testingAccount = ref<number | null>(null);
const subscribingAccount = ref<number | null>(null);
const testResults = reactive<Record<number, ChannelTestResult | null>>({});

// One card per platform: a live connected account first (e.g. the page picked through
// "Connect with Facebook", even when a simulator account exists), else the first
// account that is not disconnected, else the first one on file.
const cards = computed(() =>
    (page.props.platforms ?? []).map((p) => {
        const list = props.accounts.filter((a) => a.platform === p.value);
        const active = list.filter((a) => a.status !== 'disconnected');
        return { platform: p.value, account: active.find((a) => a.driver === 'live') ?? active[0] ?? list[0] ?? null };
    }),
);

// Outcome of the "Connect with Facebook" flow (flashed once by the server).
const flashTones = {
    connected: 'success',
    cancelled: 'info',
    subscribe_failed: 'warning',
    missing_tasks: 'warning',
    expired: 'warning',
} as const;
const dismissedFlash = ref(false);
const facebookFlash = computed(() => {
    const flash = props.facebookLogin.flash;
    if (!flash || dismissedFlash.value) return null;
    return {
        tone: flashTones[flash.code as keyof typeof flashTones] ?? 'error',
        message: t(`settings.channels.facebook.flash.${flash.code}`, { name: flash.name ?? '' }),
        detail: flash.detail ?? null,
    };
});
const flashClasses = {
    success: 'border-ok/30 bg-ok-soft',
    info: 'border-line bg-sunken',
    warning: 'border-warn/40 bg-warn-soft',
    error: 'border-danger/30 bg-danger-soft',
} as const;
const flashIcons = { success: CircleCheck, info: Info, warning: TriangleAlert, error: CircleAlert } as const;

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
        toast.push(
            data.ok ? t('settings.channels.test_ok', { name: data.page_name ?? data.ig_username ?? '—' }) : (data.error ?? t('common.error')),
            data.ok ? 'success' : 'error',
        );
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
    { key: 'attempts', label: t('settings.channels.attempts'), numeric: true },
    { key: 'error', label: t('settings.channels.error') },
    { key: 'created_at', label: t('orders.columns.date') },
    { key: 'actions', label: t('ui.actions'), align: 'end' },
]);

// ---- the health table (mockup): one row per channel, failures in red only when there are some ----------------
const now = useNow();
type Health = { label: string; tone: 'ok' | 'warn' | 'danger' | 'neutral' };

function health(platform: PlatformValue, account: ChannelAccount | null): Health {
    if (!account || account.status === 'disconnected') return { label: t('settings.channels.status.disconnected'), tone: 'neutral' };
    if (account.driver === 'fake') return { label: t('settings.channels.fake_badge'), tone: 'warn' };
    if ((props.webhookRejections?.[platform]?.count ?? 0) > 0) return { label: t('settings.channels.health.rejected'), tone: 'danger' };
    if (account.status === 'error') return { label: t('settings.channels.status.error'), tone: 'danger' };
    return { label: t('settings.channels.health.ok'), tone: 'ok' };
}

const healthRows = computed(() =>
    cards.value.map((card) => ({
        id: card.platform,
        platform: card.platform,
        name: card.account?.name ?? null,
        last: card.account?.last_webhook_at ?? null,
        failed: props.failedEvents.filter((e) => e.provider === card.platform).length,
        health: health(card.platform, card.account),
    })),
);
const healthColumns = computed<Column[]>(() => [
    { key: 'platform', label: t('settings.channels.health.channel'), primary: true },
    { key: 'last', label: t('settings.channels.last_webhook') },
    { key: 'failed', label: t('settings.channels.health.failed'), numeric: true },
    { key: 'health', label: t('settings.channels.health.status') },
]);

const title = computed(() => t('settings_hub.items.integrations'));
const breadcrumbs = useSettingsBreadcrumbs(() => title.value);
</script>

<template>
    <Head :title="`${title} · ${t('section_tabs.channels.webhooks')}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsShell
            :title="title"
            :description="t('settings.channels.description')"
            :tabs="sectionTabs.channels(t)"
            :tabs-label="t('section_tabs.channels.label')"
        >
            <div
                v-if="facebookFlash"
                role="status"
                class="flex items-start gap-2 rounded-lg border px-4 py-3 text-13"
                :class="flashClasses[facebookFlash.tone]"
            >
                <component :is="flashIcons[facebookFlash.tone]" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">{{ facebookFlash.message }}</p>
                    <p v-if="facebookFlash.detail" class="mt-0.5 break-words text-xs text-fg-2" dir="ltr">{{ facebookFlash.detail }}</p>
                </div>
                <button
                    type="button"
                    class="-m-1 grid size-10 shrink-0 place-items-center rounded-md text-fg-2 hover:bg-card/60 hover:text-foreground"
                    :aria-label="t('common.close')"
                    @click="dismissedFlash = true"
                >
                    <X class="size-4" aria-hidden="true" />
                </button>
            </div>

            <DataTable table-id="settings-webhook-health" :columns="healthColumns" :rows="healthRows" :caption="t('section_tabs.channels.webhooks')">
                <template #cell-platform="{ row }">
                    <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                        <PlatformBadge :platform="row.platform" show-label />
                        <bdi v-if="row.name" class="truncate text-13 text-fg-2">{{ row.name }}</bdi>
                    </span>
                </template>
                <template #cell-last="{ row }">
                    <span class="whitespace-nowrap tabular-nums text-fg-2" :title="formatDateTime(row.last, locale) || undefined">{{
                        row.last ? formatSince(row.last, locale, now) : t('ui.never')
                    }}</span>
                </template>
                <template #cell-failed="{ row }">
                    <span class="tabular-nums" :class="row.failed > 0 ? 'font-semibold text-danger' : 'text-fg-3'">{{
                        formatCount(row.failed, locale)
                    }}</span>
                </template>
                <template #cell-health="{ row }">
                    <StatusChip :label="row.health.label" :tone="row.health.tone" />
                </template>
            </DataTable>

            <section class="grid gap-2">
                <h2 class="text-base font-bold">{{ t('settings.channels.failed_events') }}</h2>
                <DataTable
                    table-id="settings-channels"
                    :columns="columns"
                    :rows="failedEvents"
                    :empty="t('settings.channels.no_failed')"
                    :empty-icon="CircleCheck"
                >
                    <template #cell-event="{ row }">
                        <span class="font-semibold" dir="ltr">#{{ row.id }} · {{ row.provider }} · {{ row.event_type ?? '—' }}</span>
                    </template>
                    <template #cell-attempts="{ row }"
                        ><span class="tabular-nums">{{ formatCount(row.attempts, locale) }}</span></template
                    >
                    <template #cell-error="{ row }"
                        ><span class="line-clamp-2 max-w-md break-words text-danger" dir="ltr">{{ row.error ?? '—' }}</span></template
                    >
                    <template #cell-created_at="{ row }"
                        ><span class="whitespace-nowrap tabular-nums text-fg-2">{{ formatDateTime(row.created_at, locale) }}</span></template
                    >
                    <template #cell-actions="{ row }">
                        <IconAction
                            :icon="RotateCw"
                            size="sm"
                            variant="outline"
                            :label="t('settings.channels.reprocess')"
                            :disabled="busyEvent !== null"
                            :loading="busyEvent === row.id"
                            @click="reprocess(row)"
                        />
                    </template>
                </DataTable>
            </section>

            <section class="grid gap-3">
                <div>
                    <h2 class="text-base font-bold">{{ t('settings.channels.per_channel.title') }}</h2>
                    <p class="text-13 text-fg-2">{{ t('settings.channels.per_channel.description') }}</p>
                </div>
                <div class="grid items-start gap-4 lg:grid-cols-2">
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
                        :facebook-login="facebookLogin"
                        :rejections="webhookRejections?.[card.platform] ?? null"
                        @driver="changeDriver"
                        @save-live="saveLive"
                        @test="testAccount"
                        @subscribe="subscribeAccount"
                    />
                </div>
            </section>
        </SettingsShell>
    </AppLayout>
</template>
