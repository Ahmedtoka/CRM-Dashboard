<script setup lang="ts">
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { formatDateTime } from '@/lib/format';
import type { ChannelAccount, ChannelTestResult } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { Copy, LoaderCircle } from 'lucide-vue-next';
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
    <section class="flex flex-col gap-3 rounded-lg border bg-card p-3 text-xs">
        <header class="flex items-center gap-2">
            <PlatformBadge :platform="platform" show-label />
            <span v-if="account" class="truncate text-muted-foreground">{{ account.name }}</span>
            <StatusChip v-if="account" class="ms-auto" :label="t(`settings.channels.status.${account.status}`)" :tone="statusTone[account.status]" />
        </header>

        <p v-if="!account" class="py-4 text-center text-muted-foreground">{{ t('settings.channels.no_account') }}</p>

        <template v-else>
            <label class="grid gap-1">
                <span class="font-medium">{{ t('settings.channels.driver') }}</span>
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
            ]" :key="field.label" class="grid gap-1">
                <span class="font-medium">{{ field.label }}</span>
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
                    <dd class="mt-0.5 break-words rounded bg-red-50 px-2 py-1 text-red-700" dir="ltr">{{ account.last_error }}</dd>
                </div>
            </dl>

            <form v-if="isMeta && account.driver === 'live'" class="grid gap-2 border-t pt-2" @submit.prevent="saveLive">
                <p class="font-medium">{{ t('settings.channels.live_setup') }}</p>

                <label class="grid gap-1">
                    <span>{{ isInstagram ? t('settings.channels.instagram_business_id') : t('settings.channels.page_id') }}</span>
                    <input v-model="externalId" type="text" dir="ltr" class="h-8 rounded-md border border-input bg-background px-2" />
                </label>

                <template v-if="isInstagram">
                    <label class="grid gap-1">
                        <span>{{ t('settings.channels.linked_facebook_page') }}</span>
                        <select v-model.number="linkedFacebookAccountId" class="h-8 rounded-md border border-input bg-background px-2">
                            <option :value="null" disabled>{{ t('settings.channels.select_page') }}</option>
                            <option v-for="fb in facebookAccounts" :key="fb.id" :value="fb.id">{{ fb.name }}</option>
                        </select>
                    </label>
                    <p class="text-2xs text-muted-foreground">{{ t('settings.channels.instagram_no_token_note') }}</p>
                </template>

                <label v-else class="grid gap-1">
                    <span>{{ t('settings.channels.access_token') }}</span>
                    <input
                        v-model="accessToken"
                        type="password"
                        dir="ltr"
                        autocomplete="off"
                        :placeholder="account.has_token ? t('settings.channels.token_saved') : t('settings.channels.token_placeholder')"
                        class="h-8 rounded-md border border-input bg-background px-2"
                    />
                </label>

                <button type="submit" class="h-8 rounded-md border px-2 font-medium hover:bg-muted disabled:opacity-50" :disabled="busy">
                    {{ t('common.save') }}
                </button>

                <div class="flex gap-2">
                    <button type="button" class="flex h-8 flex-1 items-center justify-center gap-1 rounded-md border px-2 hover:bg-muted disabled:opacity-50" :disabled="testing" @click="emit('test', account)">
                        <LoaderCircle v-if="testing" class="size-3 animate-spin" aria-hidden="true" />
                        {{ t('settings.channels.test') }}
                    </button>
                    <button
                        type="button"
                        class="flex h-8 flex-1 items-center justify-center gap-1 rounded-md border px-2 hover:bg-muted disabled:opacity-50"
                        :disabled="subscribing"
                        @click="emit('subscribe', account)"
                    >
                        <LoaderCircle v-if="subscribing" class="size-3 animate-spin" aria-hidden="true" />
                        {{ t('settings.channels.subscribe') }}
                    </button>
                </div>

                <p v-if="testResult?.ok" class="rounded bg-emerald-50 px-2 py-1 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                    {{ t('settings.channels.test_ok', { name: testResult.page_name ?? testResult.ig_username ?? '—' }) }}
                </p>
                <p v-else-if="testResult && !testResult.ok" class="rounded bg-red-50 px-2 py-1 text-red-700">
                    {{ testResult.error }}
                </p>

                <p v-if="testResult?.note" class="rounded bg-amber-50 px-2 py-1 text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                    {{ testResult.note }}
                </p>
            </form>
        </template>
    </section>
</template>
