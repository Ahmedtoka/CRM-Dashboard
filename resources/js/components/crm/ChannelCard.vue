<script setup lang="ts">
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import WebhookRejectionLine from '@/components/crm/WebhookRejectionLine.vue';
import { Button, buttonVariants } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ChannelAccount, ChannelTestResult, FacebookLoginSettings, WebhookRejectionSummary } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { ChevronDown, CircleCheck, Copy, Facebook } from 'lucide-vue-next';
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
    /** This platform's webhooks refused for a bad signature in the last day (2026-10-17). */
    rejections?: WebhookRejectionSummary | null;
}>();
const emit = defineEmits<{
    driver: [account: ChannelAccount, driver: 'fake' | 'live'];
    'save-live': [account: ChannelAccount, payload: { external_id: string; credentials: Record<string, unknown> }];
    test: [account: ChannelAccount];
    subscribe: [account: ChannelAccount];
}>();

const { t, locale } = useI18n();
const toast = useToast();

const statusTone = { connected: 'ok', error: 'danger', disconnected: 'neutral' } as const;

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
    <section class="flex min-w-0 flex-col gap-3 rounded-lg border border-line bg-card p-4 text-13">
        <header class="flex items-center gap-2">
            <PlatformBadge :platform="platform" show-label />
            <span v-if="account" class="truncate text-fg-2">{{ account.name }}</span>
            <!-- A fake-driver account is never really "connected": say it is a test account instead. -->
            <StatusChip v-if="account && account.driver === 'fake'" class="ms-auto" :label="t('settings.channels.fake_badge')" tone="warn" />
            <StatusChip
                v-else-if="account"
                class="ms-auto"
                :label="t(`settings.channels.status.${account.status}`)"
                :tone="statusTone[account.status]"
            />
        </header>
        <p v-if="account?.driver === 'fake'" class="rounded-md bg-warn-soft px-3 py-2 text-xs text-foreground">
            {{ t('settings.channels.fake_hint') }}
        </p>

        <div v-if="isFacebook && facebookLogin" class="grid gap-2 rounded-md border border-line bg-surface-2 p-3">
            <p class="text-xs leading-relaxed text-fg-2">{{ t('settings.channels.facebook.intro') }}</p>

            <div v-if="connectedPage" class="flex min-w-0 items-center gap-1.5 rounded-md bg-ok-soft px-2 py-1.5">
                <CircleCheck class="size-3.5 shrink-0 text-ok" aria-hidden="true" />
                <span class="shrink-0 text-fg-2">{{ t('settings.channels.facebook.connected_page') }}:</span>
                <span class="truncate font-semibold">{{ connectedPage.name }}</span>
            </div>

            <a v-if="facebookLogin.enabled" href="/settings/channels/facebook/connect" :class="cn(buttonVariants(), 'h-10')">
                <Facebook aria-hidden="true" />
                {{ connectedPage ? t('settings.channels.facebook.reconnect') : t('settings.channels.facebook.connect') }}
            </a>
            <template v-else>
                <Button type="button" disabled class="h-10"><Facebook aria-hidden="true" />{{ t('settings.channels.facebook.connect') }}</Button>
                <p class="text-xs text-fg-2">{{ t('settings.channels.facebook.disabled_hint') }}</p>
            </template>
            <p v-if="facebookLogin.enabled && facebookLogin.secret_missing" class="rounded-md bg-warn-soft px-2 py-1 text-xs">
                {{ t('settings.channels.facebook.secret_missing_hint') }}
            </p>

            <div class="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-1">
                <span class="text-xs font-semibold">{{ t('settings.channels.facebook.redirect_uri') }}</span>
                <div class="flex items-center gap-1">
                    <code class="min-w-0 flex-1 truncate rounded bg-card px-2 py-1 text-xs" dir="ltr" :title="facebookLogin.redirect_uri">{{
                        facebookLogin.redirect_uri
                    }}</code>
                    <button
                        type="button"
                        class="grid size-10 shrink-0 place-items-center rounded-md text-fg-2 hover:bg-sunken hover:text-foreground sm:size-8"
                        :aria-label="`${t('ui.copy')} ${t('settings.channels.facebook.redirect_uri')}`"
                        @click="copy(facebookLogin.redirect_uri)"
                    >
                        <Copy class="size-3.5" aria-hidden="true" />
                    </button>
                </div>
                <span class="text-xs text-fg-2">{{ t('settings.channels.facebook.redirect_uri_hint') }}</span>
            </div>
        </div>

        <p v-if="!account" class="py-4 text-center text-fg-2">{{ t('settings.channels.no_account') }}</p>

        <template v-else>
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.channels.driver') }}</span>
                <select
                    :value="account.driver"
                    class="h-10 rounded-md border border-line-strong bg-card px-3 sm:h-9"
                    :disabled="busy"
                    @change="emit('driver', account, ($event.target as HTMLSelectElement).value as 'fake' | 'live')"
                >
                    <option value="fake">{{ t('settings.channels.driver_fake') }}</option>
                    <option value="live">{{ t('settings.channels.driver_live') }}</option>
                </select>
            </label>

            <div
                v-for="field in [
                    { label: t('settings.channels.webhook_url'), value: account.webhook_url },
                    // TikTok has no verify-token handshake (x6): it signs every delivery with the app secret instead.
                    ...(platform === 'tiktok' ? [] : [{ label: t('settings.channels.verify_token'), value: account.verify_token }]),
                ]"
                :key="field.label"
                class="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-1"
            >
                <span class="text-sm font-semibold">{{ field.label }}</span>
                <div class="flex items-center gap-1">
                    <code class="min-w-0 flex-1 truncate rounded bg-sunken px-2 py-1 text-xs" dir="ltr">{{ field.value || '—' }}</code>
                    <button
                        v-if="field.value"
                        type="button"
                        class="grid size-10 shrink-0 place-items-center rounded-md text-fg-2 hover:bg-sunken hover:text-foreground sm:size-8"
                        :aria-label="`${t('ui.copy')} ${field.label}`"
                        @click="copy(field.value)"
                    >
                        <Copy class="size-3.5" aria-hidden="true" />
                    </button>
                </div>
            </div>

            <dl class="grid gap-1">
                <div class="flex justify-between gap-2">
                    <dt class="text-fg-2">{{ t('settings.channels.last_webhook') }}</dt>
                    <dd class="tabular-nums">{{ formatDateTime(account.last_webhook_at, locale) || t('ui.never') }}</dd>
                </div>
                <WebhookRejectionLine :summary="rejections" secret="app_secret" />
                <div v-if="account.last_error">
                    <dt class="text-fg-2">{{ t('settings.channels.last_error') }}</dt>
                    <dd class="mt-0.5 break-words rounded-md bg-danger-soft px-2 py-1 text-foreground" dir="ltr">{{ account.last_error }}</dd>
                </div>
            </dl>

            <div v-if="isMeta && account.driver === 'live'" class="grid gap-2 border-t border-line pt-2">
                <form v-if="isInstagram" class="grid gap-2" @submit.prevent="saveLive">
                    <p class="font-medium">{{ t('settings.channels.live_setup') }}</p>

                    <label class="grid gap-1">
                        <span class="text-sm font-semibold">{{ t('settings.channels.instagram_business_id') }}</span>
                        <input v-model="externalId" type="text" dir="ltr" class="h-10 rounded-md border border-line-strong bg-card px-3 sm:h-9" />
                    </label>

                    <label class="grid gap-1">
                        <span class="text-sm font-semibold">{{ t('settings.channels.linked_facebook_page') }}</span>
                        <select v-model.number="linkedFacebookAccountId" class="h-10 rounded-md border border-line-strong bg-card px-3 sm:h-9">
                            <option :value="null" disabled>{{ t('settings.channels.select_page') }}</option>
                            <option v-for="fb in facebookAccounts" :key="fb.id" :value="fb.id">{{ fb.name }}</option>
                        </select>
                    </label>
                    <p class="text-xs text-fg-2">{{ t('settings.channels.instagram_no_token_note') }}</p>

                    <Button type="submit" variant="outline" size="sm" class="w-fit max-sm:h-10" :disabled="busy">
                        {{ t('common.save') }}
                    </Button>
                </form>

                <!-- Messenger: "Connect with Facebook" above is the normal path; pasting a Page ID/token stays available here. -->
                <details v-else class="group rounded-md border border-line">
                    <summary
                        class="flex cursor-pointer list-none items-center justify-between gap-2 px-2 py-1.5 font-medium hover:bg-sunken [&::-webkit-details-marker]:hidden"
                    >
                        {{ t('settings.channels.facebook.manual') }}
                        <ChevronDown class="size-3.5 text-fg-2 transition-transform group-open:rotate-180" aria-hidden="true" />
                    </summary>
                    <form class="grid gap-2 border-t border-line p-2" @submit.prevent="saveLive">
                        <label class="grid gap-1">
                            <span class="text-sm font-semibold">{{ t('settings.channels.page_id') }}</span>
                            <input v-model="externalId" type="text" dir="ltr" class="h-10 rounded-md border border-line-strong bg-card px-3 sm:h-9" />
                        </label>

                        <label class="grid gap-1">
                            <span class="text-sm font-semibold">{{ t('settings.channels.access_token') }}</span>
                            <input
                                v-model="accessToken"
                                type="password"
                                dir="ltr"
                                autocomplete="off"
                                :placeholder="account.has_token ? t('settings.channels.token_saved') : t('settings.channels.token_placeholder')"
                                class="h-10 rounded-md border border-line-strong bg-card px-3 sm:h-9"
                            />
                        </label>

                        <Button type="submit" variant="outline" size="sm" class="w-fit max-sm:h-10" :disabled="busy">
                            {{ t('common.save') }}
                        </Button>
                    </form>
                </details>

                <div class="flex gap-2">
                    <Button variant="outline" size="sm" class="flex-1 max-sm:h-10" type="button" @click="emit('test', account)" :loading="testing">
                        {{ t('settings.channels.test') }}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="flex-1 max-sm:h-10"
                        type="button"
                        @click="emit('subscribe', account)"
                        :loading="subscribing"
                    >
                        {{ t('settings.channels.subscribe') }}
                    </Button>
                </div>

                <p v-if="testResult?.ok" class="rounded-md bg-ok-soft px-2 py-1 text-foreground">
                    {{ t('settings.channels.test_ok', { name: testResult.page_name ?? testResult.ig_username ?? '—' }) }}
                </p>
                <p v-else-if="testResult && !testResult.ok" class="rounded bg-danger-soft px-2 py-1 text-foreground">
                    {{ testResult.error }}
                </p>

                <p v-if="testResult?.note" class="rounded bg-warn-soft px-2 py-1 text-foreground">
                    {{ testResult.note }}
                </p>
            </div>
        </template>
    </section>
</template>
