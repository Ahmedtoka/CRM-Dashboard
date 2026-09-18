<script setup lang="ts">
import FormDialog from '@/components/crm/FormDialog.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import AccountSummary from '@/components/crm/integrations/AccountSummary.vue';
import HowToSteps from '@/components/crm/integrations/HowToSteps.vue';
import IntegrationCard from '@/components/crm/integrations/IntegrationCard.vue';
import MetaSetupHint from '@/components/crm/integrations/MetaSetupHint.vue';
import SystemUserTokenForm from '@/components/crm/integrations/SystemUserTokenForm.vue';
import WhatsAppConnectForm from '@/components/crm/integrations/WhatsAppConnectForm.vue';
import { buttonVariants } from '@/components/ui/button';
import { useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import { accountState, integrationError, isActive } from '@/lib/integrations';
import { cn } from '@/lib/utils';
import type {
    FacebookLoginSettings,
    HealthCheckItem,
    IntegrationAccount,
    IntegrationAccounts,
    IntegrationsMeta,
    ShopifySummary,
} from '@/types/admin';
import { Head, Link } from '@inertiajs/vue3';
import {
    ChevronDown,
    CircleAlert,
    CircleCheck,
    Facebook,
    Info,
    Instagram,
    LoaderCircle,
    MessageCircle,
    Music2,
    Settings2,
    ShoppingBag,
    TriangleAlert,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps<{
    accounts: IntegrationAccounts;
    shopify: ShopifySummary | null;
    meta: IntegrationsMeta;
    facebookLogin: FacebookLoginSettings;
}>();

const { t, locale } = useI18n();
const api = useApi();
const toast = useToast();

type MetaPlatform = 'facebook' | 'instagram' | 'whatsapp';

const accounts = reactive<IntegrationAccounts>({ ...props.accounts });
watch(
    () => props.accounts,
    (next) => Object.assign(accounts, next),
);

const busy = reactive<Record<MetaPlatform, 'test' | 'fix' | 'reconnect' | null>>({ facebook: null, instagram: null, whatsapp: null });

const facebook = computed(() => accounts.facebook);
const instagram = computed(() => accounts.instagram);
const whatsapp = computed(() => accounts.whatsapp);
const facebookActive = computed(() => isActive(accounts.facebook));

const appReady = computed(() => props.meta.app_id_set && props.meta.app_secret_set);

// ---- "Connect with Facebook" outcome (flashed once by the server) -----------------
const flashTones = { connected: 'success', cancelled: 'info', subscribe_failed: 'warning', missing_tasks: 'warning', expired: 'warning' } as const;
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
    success: 'border-success/30 bg-success/10',
    info: 'border-border bg-muted',
    warning: 'border-warning/40 bg-warning/15',
    error: 'border-destructive/30 bg-destructive/10',
} as const;
const flashIcons = { success: CircleCheck, info: Info, warning: TriangleAlert, error: CircleAlert } as const;

// ---- shared account actions ------------------------------------------------------
function applyAccounts(payload: { accounts?: IntegrationAccounts; account?: IntegrationAccount }): void {
    if (payload.accounts) Object.assign(accounts, payload.accounts);
    else if (payload.account) accounts[payload.account.platform] = payload.account;
}

function onConnected(payload: { account: IntegrationAccount; accounts: IntegrationAccounts; subscribed: boolean }): void {
    applyAccounts(payload);
    toast.push(
        payload.subscribed
            ? t('settings.integrations.connected_toast', { name: payload.account.name })
            : t('settings.integrations.subscribe_failed_toast', { name: payload.account.name }),
        payload.subscribed ? 'success' : 'error',
    );
    if (payload.account.platform === 'facebook') {
        facebookReconnect.value = false;
        if (!isActive(accounts.instagram)) void discoverInstagram();
    }
    if (payload.account.platform === 'whatsapp') whatsappForm.value = false;
}

async function test(account: IntegrationAccount): Promise<void> {
    busy[account.platform] = 'test';
    try {
        const { data } = await api.post<{ account: IntegrationAccount }>(`/settings/integrations/${account.id}/test`);
        applyAccounts(data);
        const status = data.account.health_status ?? 'ok';
        toast.push(t(`settings.integrations.test_result.${status}`), status === 'ok' ? 'success' : status === 'warning' ? 'info' : 'error');
    } catch (e) {
        toast.push(integrationError(e, t).message, 'error');
    } finally {
        busy[account.platform] = null;
    }
}

async function fix(account: IntegrationAccount, action: NonNullable<HealthCheckItem['fix']>): Promise<void> {
    busy[account.platform] = 'fix';
    try {
        const { data } = await api.post<{ account: IntegrationAccount }>(`/settings/integrations/${account.id}/fix`, { action });
        applyAccounts(data);
        toast.push(t('settings.integrations.fixed_toast'), 'success');
    } catch (e) {
        const data = (e as { response?: { data?: { account?: IntegrationAccount } } }).response?.data;
        if (data?.account) applyAccounts({ account: data.account });
        const err = integrationError(e, t);
        toast.push(err.detail ? `${err.message} (${err.detail})` : err.message, 'error');
    } finally {
        busy[account.platform] = null;
    }
}

// ---- disconnect (with consequences spelled out) ---------------------------------
const disconnectTarget = ref<IntegrationAccount | null>(null);
const disconnecting = ref(false);

async function confirmDisconnect(): Promise<void> {
    const account = disconnectTarget.value;
    if (!account) return;
    disconnecting.value = true;
    try {
        const { data } = await api.delete<{ accounts: IntegrationAccounts }>(`/settings/integrations/${account.id}`);
        applyAccounts(data);
        toast.push(t('settings.integrations.disconnected_toast', { name: account.name }), 'success');
        disconnectTarget.value = null;
        if (account.platform === 'facebook') instagramDiscovery.status = 'idle';
    } catch (e) {
        toast.push(integrationError(e, t).message, 'error');
    } finally {
        disconnecting.value = false;
    }
}

// ---- Facebook ---------------------------------------------------------------------
const facebookReconnect = ref(false);
const facebookState = computed(() => accountState(facebook.value));
const facebookDetail = computed(() => {
    const method = facebook.value?.profile.method;
    return method === 'system_user'
        ? t('settings.integrations.facebook.via_system_user')
        : method === 'login'
          ? t('settings.integrations.facebook.via_login')
          : (facebook.value?.profile.category ?? null);
});
const facebookSteps = computed(() => [1, 2, 3].map((n) => t(`settings.integrations.facebook.step_${n}`)));

// ---- Instagram ----------------------------------------------------------------------
const instagramDiscovery = reactive<{
    status: 'idle' | 'loading' | 'found' | 'none' | 'error';
    ig: { id: string; username: string | null; name: string | null; picture: string | null } | null;
    page: string;
    error: { message: string; detail: string | null } | null;
}>({ status: 'idle', ig: null, page: '', error: null });
const instagramConnecting = ref(false);

async function discoverInstagram(): Promise<void> {
    instagramDiscovery.status = 'loading';
    instagramDiscovery.error = null;
    try {
        const { data } = await api.get<{ instagram: typeof instagramDiscovery.ig; page_name: string }>('/settings/integrations/instagram/discover', {
            silent: true,
        });
        instagramDiscovery.ig = data.instagram;
        instagramDiscovery.page = data.page_name;
        instagramDiscovery.status = data.instagram ? 'found' : 'none';
    } catch (e) {
        instagramDiscovery.status = 'error';
        instagramDiscovery.error = integrationError(e, t);
    }
}

async function connectInstagram(): Promise<void> {
    instagramConnecting.value = true;
    if (instagram.value) busy.instagram = 'reconnect';
    try {
        const { data } = await api.post<{ account: IntegrationAccount; accounts: IntegrationAccounts; subscribed: boolean }>(
            '/settings/integrations/instagram/connect',
        );
        onConnected(data);
    } catch (e) {
        const err = integrationError(e, t);
        instagramDiscovery.status = 'error';
        instagramDiscovery.error = err;
        toast.push(err.message, 'error');
    } finally {
        instagramConnecting.value = false;
        busy.instagram = null;
    }
}

const instagramState = computed(() => {
    if (instagramConnecting.value && !isActive(instagram.value)) return 'connecting' as const;
    return accountState(instagram.value);
});
const instagramHandle = computed(() =>
    instagramDiscovery.ig?.username ? `@${instagramDiscovery.ig.username}` : (instagramDiscovery.ig?.name ?? ''),
);
const instagramSteps = computed(() => [1, 2, 3, 4].map((n) => t(`settings.integrations.instagram.step_${n}`)));

onMounted(() => {
    if (facebookActive.value && !isActive(instagram.value)) void discoverInstagram();
});

// ---- WhatsApp ---------------------------------------------------------------------
const whatsappForm = ref(false);
const whatsappState = computed(() => accountState(whatsapp.value));
const whatsappSteps = computed(() => [1, 2, 3, 4].map((n) => t(`settings.integrations.whatsapp.step_${n}`)));
const qualityTone: Record<string, string> = { GREEN: 'text-success', YELLOW: 'text-warning', RED: 'text-destructive' };

// ---- Shopify / TikTok -------------------------------------------------------------
const shopifyState = computed(() => {
    if (!props.shopify || props.shopify.status === 'disconnected')
        return props.shopify?.last_error ? ('problem' as const) : ('not_connected' as const);
    return props.shopify.status === 'connected' ? ('connected' as const) : ('problem' as const);
});

// ---- Meta app checklist -------------------------------------------------------------
const setup = computed(() => ({
    facebook: {
        object: 'page',
        fields: ['messages', 'messaging_postbacks', 'message_deliveries', 'message_reads', 'feed'],
        permissions: [
            'pages_show_list',
            'pages_messaging',
            'pages_manage_metadata',
            'pages_read_engagement',
            'pages_read_user_content',
            'pages_manage_engagement',
            'business_management',
        ],
    },
    instagram: {
        object: 'instagram',
        fields: ['messages', 'messaging_postbacks', 'messaging_seen', 'comments'],
        permissions: [
            'instagram_basic',
            'instagram_manage_messages',
            'instagram_manage_comments',
            'pages_show_list',
            'pages_manage_metadata',
            'pages_read_engagement',
            'business_management',
        ],
    },
    whatsapp: {
        object: 'whatsapp_business_account',
        fields: ['messages'],
        permissions: ['whatsapp_business_messaging', 'whatsapp_business_management', 'business_management'],
    },
}));

const breadcrumbs = computed(() => [{ title: t('settings.integrations.title'), href: '/settings/integrations' }]);
</script>

<template>
    <Head :title="t('settings.integrations.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-4xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('settings.integrations.title')" :description="t('settings.integrations.description')">
                <Link
                    href="/settings/channels"
                    :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                    :title="t('settings.integrations.advanced_hint')"
                >
                    <Settings2 aria-hidden="true" />
                    {{ t('settings.integrations.advanced') }}
                </Link>
            </PageHeader>

            <p v-if="!appReady" role="alert" class="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/15 px-3 py-2.5 text-sm">
                <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                {{ t('settings.integrations.app_missing') }}
            </p>

            <div
                v-if="facebookFlash"
                role="status"
                class="flex items-start gap-2 rounded-lg border px-3 py-2.5 text-sm"
                :class="flashClasses[facebookFlash.tone]"
            >
                <component :is="flashIcons[facebookFlash.tone]" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ facebookFlash.message }}</p>
                    <p v-if="facebookFlash.detail" class="mt-0.5 break-words text-xs text-muted-foreground" dir="ltr">{{ facebookFlash.detail }}</p>
                </div>
                <button
                    type="button"
                    class="grid size-8 place-items-center rounded text-muted-foreground hover:bg-background/60 hover:text-foreground"
                    :aria-label="t('common.close')"
                    @click="dismissedFlash = true"
                >
                    <X class="size-4" aria-hidden="true" />
                </button>
            </div>

            <!-- Facebook Messenger -->
            <IntegrationCard
                :title="t('settings.integrations.facebook.title')"
                :description="t('settings.integrations.facebook.what')"
                :state="facebookState"
                :icon="Facebook"
                color="#0866FF"
                :account-name="facebookState === 'not_connected' ? null : facebook?.name"
                :account-detail="facebookDetail"
                :picture="facebookState === 'not_connected' ? null : facebook?.profile.picture"
            >
                <template v-if="facebookState === 'not_connected' || facebookReconnect">
                    <HowToSteps v-if="facebookState === 'not_connected'" :steps="facebookSteps" />
                    <div class="grid gap-2">
                        <a
                            v-if="facebookLogin.enabled"
                            href="/settings/channels/facebook/connect"
                            class="inline-flex h-10 w-fit items-center justify-center gap-2 rounded-md bg-[#0866FF] px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-[#0759E0] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0866FF]"
                        >
                            <Facebook class="size-4" aria-hidden="true" />
                            {{ t('settings.integrations.facebook.connect') }}
                        </a>
                        <p v-else class="text-xs text-muted-foreground">{{ t('settings.channels.facebook.disabled_hint') }}</p>
                    </div>
                    <details class="group rounded-md border border-border">
                        <summary
                            class="flex cursor-pointer list-none items-center justify-between gap-2 rounded-md px-3 py-2 text-xs font-medium hover:bg-muted/60 [&::-webkit-details-marker]:hidden"
                        >
                            {{ t('settings.integrations.facebook.system_user.toggle') }}
                            <ChevronDown
                                class="size-3.5 text-muted-foreground transition-transform duration-200 group-open:rotate-180"
                                aria-hidden="true"
                            />
                        </summary>
                        <div class="border-t border-border p-3">
                            <SystemUserTokenForm @connected="onConnected" />
                        </div>
                    </details>
                    <button
                        v-if="facebookReconnect"
                        type="button"
                        :class="cn(buttonVariants({ variant: 'ghost', size: 'sm' }), 'w-fit')"
                        @click="facebookReconnect = false"
                    >
                        {{ t('settings.integrations.actions.cancel') }}
                    </button>
                </template>

                <AccountSummary
                    v-else-if="facebook"
                    :account="facebook"
                    :testing="busy.facebook === 'test'"
                    :fixing="busy.facebook === 'fix'"
                    @test="test(facebook)"
                    @fix="(action) => fix(facebook!, action)"
                    @reconnect="facebookReconnect = true"
                    @disconnect="disconnectTarget = facebook"
                />

                <MetaSetupHint
                    :object="setup.facebook.object"
                    :callback-url="meta.callback_urls.facebook"
                    :verify-token="meta.verify_token"
                    :fields="setup.facebook.fields"
                    :permissions="setup.facebook.permissions"
                />
            </IntegrationCard>

            <!-- Instagram -->
            <IntegrationCard
                :title="t('settings.integrations.instagram.title')"
                :description="t('settings.integrations.instagram.what')"
                :state="instagramState"
                :icon="Instagram"
                color="#E1306C"
                :account-name="isActive(instagram) ? instagram.name : null"
                :account-detail="isActive(instagram) && facebook ? t('settings.integrations.instagram.via_page', { page: facebook.name }) : null"
                :picture="isActive(instagram) ? instagram.profile.picture : null"
            >
                <AccountSummary
                    v-if="isActive(instagram)"
                    :account="instagram"
                    :testing="busy.instagram === 'test'"
                    :fixing="busy.instagram === 'fix'"
                    :reconnecting="busy.instagram === 'reconnect'"
                    @test="test(instagram)"
                    @fix="(action) => (action === 'resubscribe' ? fix(instagram!, action) : undefined)"
                    @reconnect="connectInstagram"
                    @disconnect="disconnectTarget = instagram"
                />

                <p v-else-if="!facebookActive" class="flex items-center gap-2 rounded-md bg-muted/60 px-3 py-2 text-xs text-muted-foreground">
                    <Info class="size-3.5 shrink-0" aria-hidden="true" />
                    {{ t('settings.integrations.instagram.needs_facebook') }}
                </p>

                <template v-else>
                    <p
                        v-if="instagramDiscovery.status === 'loading' || instagramDiscovery.status === 'idle'"
                        class="flex items-center gap-2 text-xs text-muted-foreground"
                        role="status"
                    >
                        <LoaderCircle class="size-3.5 animate-spin" aria-hidden="true" />
                        {{ t('settings.integrations.instagram.looking') }}
                    </p>

                    <div
                        v-else-if="instagramDiscovery.status === 'found'"
                        class="flex flex-wrap items-center gap-3 rounded-md border border-border px-3 py-2.5"
                    >
                        <img
                            v-if="instagramDiscovery.ig?.picture"
                            :src="instagramDiscovery.ig.picture"
                            alt=""
                            class="size-9 rounded-full object-cover"
                            referrerpolicy="no-referrer"
                        />
                        <p class="min-w-0 flex-1 text-xs">
                            {{ t('settings.integrations.instagram.found', { username: instagramHandle, page: instagramDiscovery.page }) }}
                        </p>
                        <button type="button" :class="buttonVariants({ size: 'sm' })" :disabled="instagramConnecting" @click="connectInstagram">
                            <LoaderCircle v-if="instagramConnecting" class="animate-spin" aria-hidden="true" />
                            <Instagram v-else aria-hidden="true" />
                            {{ t('settings.integrations.instagram.connect_found', { username: instagramHandle }) }}
                        </button>
                    </div>

                    <template v-else>
                        <div
                            role="alert"
                            class="flex flex-wrap items-start gap-2 rounded-md px-3 py-2 text-xs"
                            :class="instagramDiscovery.status === 'error' ? 'bg-destructive/10 text-destructive' : 'bg-muted/60'"
                        >
                            <CircleAlert class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                            <div class="min-w-0 flex-1">
                                <p>
                                    {{
                                        instagramDiscovery.status === 'error'
                                            ? instagramDiscovery.error?.message
                                            : t('settings.integrations.instagram.not_linked', {
                                                  page: instagramDiscovery.page || facebook?.name || '',
                                              })
                                    }}
                                </p>
                                <p v-if="instagramDiscovery.error?.detail" class="break-words text-2xs opacity-80" dir="ltr">
                                    {{ instagramDiscovery.error.detail }}
                                </p>
                            </div>
                            <button type="button" :class="buttonVariants({ variant: 'outline', size: 'sm' })" @click="discoverInstagram">
                                {{ t('settings.integrations.instagram.retry') }}
                            </button>
                        </div>
                        <HowToSteps :steps="instagramSteps" open />
                    </template>
                </template>

                <MetaSetupHint
                    :object="setup.instagram.object"
                    :callback-url="meta.callback_urls.instagram"
                    :verify-token="meta.verify_token"
                    :fields="setup.instagram.fields"
                    :permissions="setup.instagram.permissions"
                />
            </IntegrationCard>

            <!-- WhatsApp Business -->
            <IntegrationCard
                :title="t('settings.integrations.whatsapp.title')"
                :description="t('settings.integrations.whatsapp.what')"
                :state="whatsappState"
                :icon="MessageCircle"
                color="#25D366"
                :account-name="whatsappState === 'not_connected' ? null : (whatsapp?.profile.display_phone_number ?? whatsapp?.name)"
                :account-detail="whatsappState === 'not_connected' ? null : whatsapp?.profile.verified_name"
            >
                <template v-if="whatsappState === 'not_connected' && !whatsappForm">
                    <HowToSteps :steps="whatsappSteps" />
                    <button type="button" :class="cn(buttonVariants({ size: 'sm' }), 'w-fit')" @click="whatsappForm = true">
                        <MessageCircle aria-hidden="true" />
                        {{ t('settings.integrations.whatsapp.start') }}
                    </button>
                </template>

                <WhatsAppConnectForm
                    v-if="whatsappForm"
                    :waba-id="whatsapp?.profile.waba_id"
                    :phone-number-id="whatsapp?.external_id"
                    :can-override="meta.can_override_callback"
                    @connected="onConnected"
                    @cancel="whatsappForm = false"
                />

                <AccountSummary
                    v-else-if="whatsapp && whatsappState !== 'not_connected'"
                    :account="whatsapp"
                    :testing="busy.whatsapp === 'test'"
                    :fixing="busy.whatsapp === 'fix'"
                    @test="test(whatsapp)"
                    @fix="(action) => fix(whatsapp!, action)"
                    @reconnect="whatsappForm = true"
                    @disconnect="disconnectTarget = whatsapp"
                >
                    <template #facts>
                        <div class="min-w-0">
                            <dt class="text-muted-foreground">{{ t('settings.integrations.whatsapp.quality') }}</dt>
                            <dd class="font-medium" :class="qualityTone[whatsapp.profile.quality_rating ?? ''] ?? ''">
                                {{ t(`settings.integrations.whatsapp.quality_values.${whatsapp.profile.quality_rating ?? 'UNKNOWN'}`) }}
                            </dd>
                        </div>
                    </template>
                </AccountSummary>

                <MetaSetupHint
                    :object="setup.whatsapp.object"
                    :callback-url="meta.callback_urls.whatsapp"
                    :verify-token="meta.verify_token"
                    :fields="setup.whatsapp.fields"
                    :permissions="setup.whatsapp.permissions"
                />
            </IntegrationCard>

            <div class="grid gap-4 sm:grid-cols-2">
                <IntegrationCard
                    compact
                    :title="t('settings.integrations.shopify.title')"
                    :description="t('settings.integrations.shopify.what')"
                    :state="shopifyState"
                    :icon="ShoppingBag"
                    color="#008060"
                    :account-name="
                        shopify && shopifyState !== 'not_connected'
                            ? t('settings.integrations.shopify.connected_to', { shop: shopify.shop_name ?? shopify.shop_domain ?? '' })
                            : null
                    "
                    :account-detail="
                        shopify?.connected_at ? `${t('settings.integrations.connected_since')} ${formatDateTime(shopify.connected_at, locale)}` : null
                    "
                >
                    <p
                        v-if="shopifyState === 'problem' && shopify?.last_error"
                        class="rounded-md bg-destructive/10 px-2.5 py-1.5 text-xs text-destructive"
                        dir="auto"
                    >
                        {{ shopify.last_error }}
                    </p>
                    <Link
                        href="/settings/shopify"
                        :class="cn(buttonVariants({ variant: shopifyState === 'not_connected' ? 'default' : 'outline', size: 'sm' }), 'w-fit')"
                    >
                        {{
                            shopifyState === 'not_connected' ? t('settings.integrations.shopify.connect') : t('settings.integrations.shopify.manage')
                        }}
                    </Link>
                </IntegrationCard>

                <IntegrationCard
                    compact
                    :title="t('settings.integrations.tiktok.title')"
                    :description="t('settings.integrations.tiktok.what')"
                    state="soon"
                    :icon="Music2"
                    color="#111111"
                >
                    <button type="button" disabled :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'w-fit')">
                        {{ t('settings.integrations.state.soon') }}
                    </button>
                </IntegrationCard>
            </div>
        </div>

        <FormDialog
            :open="disconnectTarget !== null"
            :title="t('settings.integrations.disconnect_dialog.title', { name: disconnectTarget?.name ?? '' })"
            :busy="disconnecting"
            destructive
            :submit-label="t('settings.integrations.disconnect_dialog.confirm')"
            @update:open="(open) => (open ? null : (disconnectTarget = null))"
            @submit="confirmDisconnect"
        >
            <p v-if="disconnectTarget" class="leading-relaxed">{{ t(`settings.integrations.disconnect_dialog.${disconnectTarget.platform}`) }}</p>
            <p class="text-xs text-muted-foreground">{{ t('settings.integrations.disconnect_dialog.keeps') }}</p>
        </FormDialog>
    </AppLayout>
</template>
