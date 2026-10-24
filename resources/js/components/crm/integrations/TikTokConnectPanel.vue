<script setup lang="ts">
import CopyField from '@/components/crm/integrations/CopyField.vue';
import { Button, buttonVariants } from '@/components/ui/button';
import { useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { integrationError } from '@/lib/integrations';
import { cn } from '@/lib/utils';
import type { IntegrationAccount, TikTokSettings } from '@/types/admin';
import { CircleAlert, Music2 } from 'lucide-vue-next';
import { ref } from 'vue';

/**
 * TikTok direct messages: «الربط بتيك توك» (OAuth on the owner's developer app) or, while the app's registered
 * redirect is still the storefront, the code (or the whole redirected URL) pasted by hand. Under it, the three
 * values the developer portal needs: the webhook URL, the redirect URL and the scopes. Nothing secret is shown.
 */
const props = defineProps<{ settings: TikTokSettings; connected: boolean }>();
const emit = defineEmits<{ connected: [payload: { account: IntegrationAccount; subscribed: boolean }] }>();

const { t } = useI18n();
const api = useApi();

const code = ref('');
const sending = ref(false);
const error = ref<{ message: string; detail: string | null } | null>(null);

async function sendCode(): Promise<void> {
    sending.value = true;
    error.value = null;
    try {
        const { data } = await api.post<{ account: IntegrationAccount; subscribed: boolean }>('/settings/integrations/tiktok/code', { code: code.value.trim() });
        code.value = '';
        emit('connected', data);
    } catch (e) {
        error.value = integrationError(e, t);
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <div class="grid gap-3" data-testid="tiktok-connect">
        <p v-if="!props.settings.enabled" role="alert" class="flex items-start gap-2 rounded-md border border-warn/40 bg-warn-soft px-3 py-2 text-13">
            <CircleAlert class="mt-0.5 size-4 shrink-0 text-warn" aria-hidden="true" />
            {{ t('settings.integrations.tiktok.keys_missing') }}
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <a v-if="settings.enabled" :href="settings.connect_url" :class="cn(buttonVariants(), 'h-10 w-fit')">
                <Music2 aria-hidden="true" />
                {{ connected ? t('settings.integrations.actions.reconnect') : t('settings.integrations.tiktok.connect') }}
            </a>
            <Button v-else type="button" disabled class="h-10"><Music2 aria-hidden="true" />{{ t('settings.integrations.tiktok.connect') }}</Button>
            <span v-if="settings.driver === 'fake'" class="rounded-md bg-warn-soft px-2 py-1 text-2xs">{{ t('settings.integrations.tiktok.fake_driver') }}</span>
        </div>

        <details class="group rounded-md border border-line bg-card text-xs">
            <summary class="flex min-h-10 cursor-pointer list-none items-center gap-2 rounded-md px-3 py-2 font-medium hover:bg-sunken [&::-webkit-details-marker]:hidden">
                {{ t('settings.integrations.tiktok.paste_toggle') }}
            </summary>
            <form class="grid gap-2 border-t border-line p-3" @submit.prevent="sendCode">
                <p class="leading-relaxed text-fg-2">{{ t('settings.integrations.tiktok.paste_hint') }}</p>
                <input
                    v-model="code"
                    type="text"
                    dir="ltr"
                    required
                    minlength="6"
                    autocomplete="off"
                    spellcheck="false"
                    :placeholder="t('settings.integrations.tiktok.paste_placeholder')"
                    class="h-9 rounded-md border border-line-strong bg-card px-2.5 text-sm"
                />
                <p v-if="error" role="alert" class="rounded-md bg-danger-soft px-2 py-1 text-danger">
                    {{ error.message }}<span v-if="error.detail" class="block break-words text-2xs" dir="ltr">{{ error.detail }}</span>
                </p>
                <Button type="submit" variant="outline" size="sm" class="w-fit" :loading="sending" :disabled="code.trim().length < 6">
                    {{ t('settings.integrations.tiktok.paste_send') }}
                </Button>
            </form>
        </details>

        <details class="group rounded-md border border-line bg-card text-xs">
            <summary class="flex min-h-10 cursor-pointer list-none items-center gap-2 rounded-md px-3 py-2 font-medium hover:bg-sunken [&::-webkit-details-marker]:hidden">
                {{ t('settings.integrations.tiktok.portal_title') }}
            </summary>
            <div class="grid gap-3 border-t border-line p-3">
                <p class="leading-relaxed text-fg-2">{{ t('settings.integrations.tiktok.portal_intro') }}</p>
                <CopyField :label="t('settings.integrations.tiktok.webhook_url')" :value="settings.callback_url" />
                <CopyField :label="t('settings.integrations.tiktok.redirect_url')" :value="settings.redirect_uri" />
                <CopyField :label="t('settings.integrations.tiktok.app_id')" :value="settings.app_id" />
                <CopyField :label="t('settings.integrations.tiktok.scopes')" :value="settings.scopes.join(', ')" />
                <p class="text-2xs text-fg-2">{{ t('settings.integrations.tiktok.event_note') }}</p>
            </div>
        </details>
    </div>
</template>
