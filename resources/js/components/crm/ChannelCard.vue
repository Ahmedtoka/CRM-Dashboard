<script setup lang="ts">
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { formatDateTime } from '@/lib/format';
import type { ChannelAccount, ChannelTestResult, FacebookLoginSettings } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { ChevronDown, CircleCheck, Copy, Facebook, LoaderCircle } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    platform: PlatformValue;
    account: ChannelAccount | null;
    busy: boolean;
    testing: boolean;
    subscribing: boolean;
    testResult: ChannelTestResult | null;
    /** Facebook channel accounts an Instagram card can link to (id + display name). */
    facebookAccounts: { id: number; name: string }[];
    /** "Connect with Facebook" settings — only used on the Messenger card. */
    facebookLogin?: FacebookLoginSettings | null;
}>();
const emit = defineEmits<{
    driver: [account: ChannelAccount, driver: 'fake' | 'live'];
    'save-live': [account: ChannelAccount, payload: { external_id: string; credentials: Record<string, unknown> }];
    test: [account: ChannelAccount];
    subscribe: [account: ChannelAccount];
}>();

const { t, locale } = useI18n();
const toast = useToast();

const statusTone = { connected: 'positive', error: 'negative', disconnected: 'neutral' } as const;

const isInstagram = computed(() => props.platform === 'instagram');
// Phase 1 of the live test covers Messenger and Instagram only (see README §6);
// the live-setup form is only meaningful for those two platforms.
const isMeta = computed(() => props.platform === 'facebook' || isInstagram.value);
const isFacebook = computed(() => props.platform === 'facebook');
// A live page with a stored token is what "connected" means for the Connect with Facebook flow.
const connectedPage = computed(() =>
    props.account && props.account.driver === 'live' && props.account.has_token && props.account.external_id ? props.account : null,
);

const externalId = ref('');
const accessToken = ref('');
const linkedFacebookAccountId = ref<number | null>(null);

// Re-seed the write-only form whenever a different (or refreshed) account arrives;
// the access token itself is never sent back by the server, so it always starts blank.
watch(
    () => props.account,
    (account) => {
        externalId.value = account?.external_id ?? '';
        accessToken.value = '';
        linkedFacebookAccountId.value = account?.linked_facebook_account_id ?? null;
    },
    { immediate: true },
);

function saveLive(): void {
    if (!props.account) return;

    // An Instagram account never has its own token — it only stores which Facebook
    // page's token to borrow (see ChannelAccount::graphToken()).
    const credentials: Record<string, unknown> = isInstagram.value
        ? { linked_facebook_account_id: linkedFacebookAccountId.value }
        : { access_token: accessToken.value };

    emit('save-live', props.account, { external_id: externalId.value, credentials });
    accessToken.value = '';
}

async function copy(value: string | null): Promise<void> {
    if (!value) return;
    try {
        await navigator.clipboard.writeText(value);
        toast.push(t('ui.copied'), 'info');
    } catch {
        toast.push(t('common.error'), 'error');
    }
}
</script>

<template>
    <section class="flex min-w-0 flex-col gap-3 rounded-lg bg-card p-3 text-xs shadow-card">
        <header class="flex items-center gap-2">
            <PlatformBadge :platform="platform" show-label />
            <span v-if="account" class="truncate text-muted-foreground">{{ account.name }}</span>
            <!-- A fake-driver account is never really "connected": say it is a test account instead. -->
            <StatusChip v-if="account && account.driver === 'fake'" class="ms-auto" :label="t('settings.channels.fake_badge')" tone="warning" />
            <StatusChip v-else-if="account" class="ms-auto" :label="t(`settings.channels.status.${account.status}`)" :tone="statusTone[account.status]" />
        </header>
        <p v-if="account?.driver === 'fake'" class="rounded-md bg-warning/15 px-2.5 py-1.5 text-2xs text-foreground">{{ t('settings.channels.fake_hint') }}</p>

        <div v-if="isFacebook && facebookLogin" class="grid gap-2 rounded-md border border-[#0866FF]/25 bg-[#0866FF]/5 p-2.5">
            <p class="text-2xs leading-relaxed text-muted-foreground">{{ t('settings.channels.facebook.intro') }}</p>

            <div v-if="connectedPage" class="flex min-w-0 items-center gap-1.5 rounded-md bg-success/10 px-2 py-1.5">
                <CircleCheck class="size-3.5 shrink-0 text-success" aria-hidden="true" />
                <span class="shrink-0 text-muted-foreground">{{ t('settings.channels.facebook.connected_page') }}:</span>
                <span class="truncate font-semibold">{{ connectedPage.name }}</span>
            </div>

            <a
                v-if="facebookLogin.enabled"
                href="/settings/channels/facebook/connect"
                class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-[#0866FF] px-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[#0759E0] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0866FF]"
            >
                <Facebook class="size-4" aria-hidden="true" />
                {{ connectedPage ? t('settings.channels.facebook.reconnect') : t('settings.channels.facebook.connect') }}
            </a>
            <template v-else>
                <button type="button" disabled class="inline-flex h-9 cursor-not-allowed items-center justify-center gap-2 rounded-md bg-[#0866FF]/40 px-3 text-sm font-semibold text-white">
                    <Facebook class="size-4" aria-hidden="true" />{{ t('settings.channels.facebook.connect') }}
                </button>
                <p class="text-2xs text-muted-foreground">{{ t('settings.channels.facebook.disabled_hint') }}</p>
            </template>
            <p v-if="facebookLogin.enabled && facebookLogin.secret_missing" class="rounded bg-warning/15 px-2 py-1 text-2xs">{{ t('settings.channels.facebook.secret_missing_hint') }}</p>

            <div class="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-1">
                <span class="text-2xs font-semibold">{{ t('settings.channels.facebook.redirect_uri') }}</span>
                <div class="flex items-center gap-1">
                    <code class="min-w-0 flex-1 truncate rounded bg-background px-2 py-1 text-2xs" dir="ltr" :title="facebookLogin.redirect_uri">{{ facebookLogin.redirect_uri }}</code>
                    <button type="button" class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground" :aria-label="`${t('ui.copy')} ${t('settings.channels.facebook.redirect_uri')}`" @click="copy(facebookLogin.redirect_uri)">
                        <Copy class="size-3.5" aria-hidden="true" />
                    </button>
                </div>
                <span class="text-2xs text-muted-foreground">{{ t('settings.channels.facebook.redirect_uri_hint') }}</span>
            </div>
        </div>

        <p v-if="!account" class="py-4 text-center text-muted-foreground">{{ t('settings.channels.no_account') }}</p>

        <template v-else>
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.channels.driver') }}</span>
                <select
                    :value="account.driver"
                    class="h-8 rounded-md border border-input bg-background px-2"
                    :disabled="busy"
                    @change="emit('driver', account, ($event.target as HTMLSelectElement).value as 'fake' | 'live')"
                >
                    <option value="fake">{{ t('settings.channels.driver_fake') }}</option>
                    <option value="live">{{ t('settings.channels.driver_live') }}</option>
                </select>
            </label>

            <div v-for="field in [
                { label: t('settings.channels.webhook_url'), value: account.webhook_url },
                { label: t('settings.channels.verify_token'), value: account.verify_token },
            ]" :key="field.label" class="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-1">
                <span class="text-sm font-semibold">{{ field.label }}</span>
                <div class="flex items-center gap-1">
                    <code class="min-w-0 flex-1 truncate rounded bg-muted px-2 py-1 text-2xs" dir="ltr">{{ field.value || '—' }}</code>
                    <button v-if="field.value" type="button" class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground" :aria-label="`${t('ui.copy')} ${field.label}`" @click="copy(field.value)">
                        <Copy class="size-3.5" aria-hidden="true" />
                    </button>
                </div>
            </div>

            <dl class="grid gap-1">
                <div class="flex justify-between gap-2">
                    <dt class="text-muted-foreground">{{ t('settings.channels.last_webhook') }}</dt>
                    <dd class="tabular-nums">{{ formatDateTime(account.last_webhook_at, locale) || t('ui.never') }}</dd>
                </div>
                <div v-if="account.last_error">
                    <dt class="text-muted-foreground">{{ t('settings.channels.last_error') }}</dt>
                    <dd class="mt-0.5 break-words rounded bg-destructive/10 px-2 py-1 text-foreground" dir="ltr">{{ account.last_error }}</dd>
                </div>
            </dl>

            <div v-if="isMeta && account.driver === 'live'" class="grid gap-2 border-t border-border pt-2">
                <form v-if="isInstagram" class="grid gap-2" @submit.prevent="saveLive">
                    <p class="font-medium">{{ t('settings.channels.live_setup') }}</p>

                    <label class="grid gap-1">
                        <span class="text-sm font-semibold">{{ t('settings.channels.instagram_business_id') }}</span>
                        <input v-model="externalId" type="text" dir="ltr" class="h-8 rounded-md border border-input bg-background px-2" />
                    </label>

                    <label class="grid gap-1">
                        <span class="text-sm font-semibold">{{ t('settings.channels.linked_facebook_page') }}</span>
                        <select v-model.number="linkedFacebookAccountId" class="h-8 rounded-md border border-input bg-background px-2">
                            <option :value="null" disabled>{{ t('settings.channels.select_page') }}</option>
                            <option v-for="fb in facebookAccounts" :key="fb.id" :value="fb.id">{{ fb.name }}</option>
                        </select>
                    </label>
                    <p class="text-2xs text-muted-foreground">{{ t('settings.channels.instagram_no_token_note') }}</p>

                    <button type="submit" class="h-8 rounded-md border border-border px-2 font-medium hover:bg-muted disabled:opacity-50" :disabled="busy">
                        {{ t('common.save') }}
                    </button>
                </form>

                <!-- Messenger: "Connect with Facebook" above is the normal path; pasting a Page ID/token stays available here. -->
                <details v-else class="group rounded-md border border-border">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-2 py-1.5 font-medium hover:bg-muted/50 [&::-webkit-details-marker]:hidden">
                        {{ t('settings.channels.facebook.manual') }}
                        <ChevronDown class="size-3.5 text-muted-foreground transition-transform group-open:rotate-180" aria-hidden="true" />
                    </summary>
                    <form class="grid gap-2 border-t border-border p-2" @submit.prevent="saveLive">
                        <label class="grid gap-1">
                            <span class="text-sm font-semibold">{{ t('settings.channels.page_id') }}</span>
                            <input v-model="externalId" type="text" dir="ltr" class="h-8 rounded-md border border-input bg-background px-2" />
                        </label>

                        <label class="grid gap-1">
                            <span class="text-sm font-semibold">{{ t('settings.channels.access_token') }}</span>
                            <input
                                v-model="accessToken"
                                type="password"
                                dir="ltr"
                                autocomplete="off"
                                :placeholder="account.has_token ? t('settings.channels.token_saved') : t('settings.channels.token_placeholder')"
                                class="h-8 rounded-md border border-input bg-background px-2"
                            />
                        </label>

                        <button type="submit" class="h-8 rounded-md border border-border px-2 font-medium hover:bg-muted disabled:opacity-50" :disabled="busy">
                            {{ t('common.save') }}
                        </button>
                    </form>
                </details>

                <div class="flex gap-2">
                    <button type="button" class="flex h-8 flex-1 items-center justify-center gap-1 rounded-md border border-border px-2 hover:bg-muted disabled:opacity-50" :disabled="testing" @click="emit('test', account)">
                        <LoaderCircle v-if="testing" class="size-3 animate-spin" aria-hidden="true" />
                        {{ t('settings.channels.test') }}
                    </button>
                    <button
                        type="button"
                        class="flex h-8 flex-1 items-center justify-center gap-1 rounded-md border border-border px-2 hover:bg-muted disabled:opacity-50"
                        :disabled="subscribing"
                        @click="emit('subscribe', account)"
                    >
                        <LoaderCircle v-if="subscribing" class="size-3 animate-spin" aria-hidden="true" />
                        {{ t('settings.channels.subscribe') }}
                    </button>
                </div>

                <p v-if="testResult?.ok" class="rounded bg-success/10 px-2 py-1 text-foreground">
                    {{ t('settings.channels.test_ok', { name: testResult.page_name ?? testResult.ig_username ?? '—' }) }}
                </p>
                <p v-else-if="testResult && !testResult.ok" class="rounded bg-destructive/10 px-2 py-1 text-foreground">
                    {{ testResult.error }}
                </p>

                <p v-if="testResult?.note" class="rounded bg-warning/15 px-2 py-1 text-foreground">
                    {{ testResult.note }}
                </p>
            </div>
        </template>
    </section>
</template>
