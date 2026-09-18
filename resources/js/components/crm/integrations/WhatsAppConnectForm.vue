<script setup lang="ts">
import { buttonVariants } from '@/components/ui/button';
import { useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { integrationError } from '@/lib/integrations';
import type { IntegrationAccount, IntegrationAccounts, WhatsAppPhoneOption } from '@/types/admin';
import { CircleAlert, LoaderCircle } from 'lucide-vue-next';
import { ref } from 'vue';

/**
 * WhatsApp Cloud API connect: WABA ID + System User token → list the WABA's numbers →
 * pick one (or type its Phone Number ID) → the server validates the pair, saves the
 * account and subscribes the app to the WABA.
 */
const props = defineProps<{ wabaId?: string | null; phoneNumberId?: string | null; canOverride: boolean }>();
const emit = defineEmits<{ connected: [payload: { account: IntegrationAccount; accounts: IntegrationAccounts; subscribed: boolean }]; cancel: [] }>();

const { t } = useI18n();
const api = useApi();

const wabaId = ref(props.wabaId ?? '');
const token = ref('');
const phoneNumberId = ref(props.phoneNumberId ?? '');
const override = ref(props.canOverride);
const numbers = ref<WhatsAppPhoneOption[] | null>(null);
const listing = ref(false);
const connecting = ref(false);
const error = ref<{ message: string; detail: string | null } | null>(null);

const idPattern = '\\d{5,30}';

function qualityLabel(value: string | null): string {
    return t(`settings.integrations.whatsapp.quality_values.${value ?? 'UNKNOWN'}`);
}

async function listNumbers(): Promise<void> {
    listing.value = true;
    error.value = null;
    try {
        const { data } = await api.post<{ phone_numbers: WhatsAppPhoneOption[] }>('/settings/integrations/whatsapp/phone-numbers', {
            waba_id: wabaId.value.trim(),
            access_token: token.value.trim(),
        });
        numbers.value = data.phone_numbers;
        if (data.phone_numbers.length === 1 || !data.phone_numbers.some((n) => n.id === phoneNumberId.value)) {
            phoneNumberId.value = data.phone_numbers[0]?.id ?? '';
        }
    } catch (e) {
        error.value = integrationError(e, t);
    } finally {
        listing.value = false;
    }
}

async function connect(): Promise<void> {
    connecting.value = true;
    error.value = null;
    try {
        const { data } = await api.post<{ account: IntegrationAccount; accounts: IntegrationAccounts; subscribed: boolean }>(
            '/settings/integrations/whatsapp/connect',
            {
                waba_id: wabaId.value.trim(),
                phone_number_id: phoneNumberId.value.trim(),
                access_token: token.value.trim(),
                override_callback: override.value,
            },
        );
        token.value = '';
        emit('connected', data);
    } catch (e) {
        error.value = integrationError(e, t);
    } finally {
        connecting.value = false;
    }
}
</script>

<template>
    <form class="grid gap-3 rounded-md border border-border p-3 text-xs" @submit.prevent="connect">
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="grid gap-1">
                <span class="font-semibold">{{ t('settings.integrations.whatsapp.waba_id') }}</span>
                <input
                    v-model="wabaId"
                    type="text"
                    inputmode="numeric"
                    dir="ltr"
                    required
                    :pattern="idPattern"
                    autocomplete="off"
                    class="h-9 rounded-md border border-input bg-background px-2.5 text-sm"
                />
            </label>
            <label class="grid gap-1">
                <span class="font-semibold">{{ t('settings.integrations.whatsapp.access_token') }}</span>
                <input
                    v-model="token"
                    type="password"
                    dir="ltr"
                    required
                    minlength="20"
                    autocomplete="off"
                    spellcheck="false"
                    :placeholder="t('settings.integrations.whatsapp.token_placeholder')"
                    class="h-9 rounded-md border border-input bg-background px-2.5 text-sm"
                />
            </label>
        </div>
        <p class="-mt-1 text-2xs text-muted-foreground">{{ t('settings.integrations.whatsapp.token_note') }}</p>

        <div>
            <button
                type="button"
                :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                :disabled="listing || !wabaId.trim() || token.trim().length < 20"
                @click="listNumbers"
            >
                <LoaderCircle v-if="listing" class="animate-spin" aria-hidden="true" />
                {{ t('settings.integrations.whatsapp.list_numbers') }}
            </button>
        </div>

        <fieldset v-if="numbers && numbers.length" class="grid gap-1.5">
            <legend class="mb-1 font-semibold">{{ t('settings.integrations.whatsapp.pick_number') }}</legend>
            <label
                v-for="number in numbers"
                :key="number.id"
                class="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-md border px-2.5 py-1.5"
                :class="phoneNumberId === number.id ? 'border-primary bg-primary/5' : 'border-border hover:bg-muted/50'"
            >
                <input v-model="phoneNumberId" type="radio" name="whatsapp-number" :value="number.id" class="accent-primary" />
                <span class="min-w-0 flex-1">
                    <span class="block font-medium tabular-nums" dir="ltr">{{ number.display_phone_number ?? number.id }}</span>
                    <span class="block truncate text-2xs text-muted-foreground">{{ number.verified_name ?? '—' }}</span>
                </span>
                <span class="text-2xs text-muted-foreground"
                    >{{ t('settings.integrations.whatsapp.quality') }}: {{ qualityLabel(number.quality_rating) }}</span
                >
            </label>
        </fieldset>
        <p v-else-if="numbers" class="text-muted-foreground">{{ t('settings.integrations.whatsapp.no_numbers') }}</p>

        <label v-if="!numbers || !numbers.length" class="grid gap-1">
            <span class="font-semibold">{{ t('settings.integrations.whatsapp.phone_number_id') }}</span>
            <input
                v-model="phoneNumberId"
                type="text"
                inputmode="numeric"
                dir="ltr"
                required
                :pattern="idPattern"
                autocomplete="off"
                class="h-9 rounded-md border border-input bg-background px-2.5 text-sm sm:max-w-xs"
            />
        </label>

        <label class="flex items-start gap-2" :class="canOverride ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'">
            <input v-model="override" type="checkbox" class="mt-0.5 rounded border-input accent-primary" :disabled="!canOverride" />
            <span>
                {{ t('settings.integrations.whatsapp.override') }}
                <span v-if="!canOverride" class="block text-2xs text-muted-foreground">{{
                    t('settings.integrations.whatsapp.override_unavailable')
                }}</span>
            </span>
        </label>

        <div v-if="error" role="alert" class="flex items-start gap-2 rounded-md bg-destructive/10 px-2.5 py-2 text-destructive">
            <CircleAlert class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            <div class="min-w-0">
                <p>{{ error.message }}</p>
                <p v-if="error.detail" class="break-words text-2xs opacity-80" dir="ltr">{{ error.detail }}</p>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="submit" :class="buttonVariants({ size: 'sm' })" :disabled="connecting || !phoneNumberId.trim() || token.trim().length < 20">
                <LoaderCircle v-if="connecting" class="animate-spin" aria-hidden="true" />
                {{ t('settings.integrations.whatsapp.connect') }}
            </button>
            <button type="button" :class="buttonVariants({ variant: 'ghost', size: 'sm' })" :disabled="connecting" @click="emit('cancel')">
                {{ t('settings.integrations.actions.cancel') }}
            </button>
        </div>
    </form>
</template>
