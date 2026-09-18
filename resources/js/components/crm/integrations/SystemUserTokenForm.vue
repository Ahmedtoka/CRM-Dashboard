<script setup lang="ts">
import { buttonVariants } from '@/components/ui/button';
import { useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { integrationError } from '@/lib/integrations';
import type { IntegrationAccount, IntegrationAccounts, SystemTokenPage } from '@/types/admin';
import { CircleAlert, LoaderCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Facebook via a System User token: paste → list the Pages it can manage (the token
 * stays on the server from here on) → pick one, or type a Page ID when the list is
 * empty → connect.
 */
const emit = defineEmits<{ connected: [payload: { account: IntegrationAccount; accounts: IntegrationAccounts; subscribed: boolean }] }>();

const { t, dir } = useI18n();
const api = useApi();

const token = ref('');
const pages = ref<SystemTokenPage[] | null>(null);
const selected = ref<string>('');
const manualPageId = ref('');
const busy = ref(false);
const error = ref<{ message: string; detail: string | null } | null>(null);

const pageId = computed(() => (pages.value && pages.value.length ? selected.value : manualPageId.value.trim()));

async function listPages(): Promise<void> {
    busy.value = true;
    error.value = null;
    try {
        const { data } = await api.post<{ pages: SystemTokenPage[] }>('/settings/integrations/facebook/system-token', { token: token.value.trim() });
        pages.value = data.pages;
        selected.value = data.pages.find((p) => p.missing_tasks.length === 0)?.id ?? '';
        token.value = ''; // kept server-side from now on
    } catch (e) {
        error.value = integrationError(e, t);
    } finally {
        busy.value = false;
    }
}

async function connect(): Promise<void> {
    if (!pageId.value) return;
    busy.value = true;
    error.value = null;
    try {
        const { data } = await api.post<{ account: IntegrationAccount; accounts: IntegrationAccounts; subscribed: boolean }>(
            '/settings/integrations/facebook/system-token/connect',
            { page_id: pageId.value },
        );
        emit('connected', data);
        pages.value = null;
    } catch (e) {
        error.value = integrationError(e, t);
        // The server forgot the token (expired session): go back to step one.
        if (
            e &&
            typeof e === 'object' &&
            'response' in e &&
            (e as { response?: { data?: { error?: string } } }).response?.data?.error === 'token_expired'
        ) {
            pages.value = null;
        }
    } finally {
        busy.value = false;
    }
}

function missingLabel(page: SystemTokenPage): string {
    return page.missing_tasks.map((task) => t(`settings.channels.facebook.task.${task}`)).join(dir.value === 'rtl' ? '، ' : ', ');
}
</script>

<template>
    <div class="grid gap-3 text-xs">
        <p class="leading-relaxed text-muted-foreground">{{ t('settings.integrations.facebook.system_user.intro') }}</p>

        <form v-if="pages === null" class="grid gap-2" @submit.prevent="listPages">
            <label class="grid gap-1">
                <span class="font-semibold">{{ t('settings.integrations.facebook.system_user.token') }}</span>
                <input
                    v-model="token"
                    type="password"
                    dir="ltr"
                    autocomplete="off"
                    spellcheck="false"
                    required
                    minlength="20"
                    class="h-9 rounded-md border border-input bg-background px-2.5 text-sm"
                />
                <span class="text-2xs text-muted-foreground">{{ t('settings.integrations.facebook.system_user.token_note') }}</span>
            </label>
            <button
                type="submit"
                :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                class="w-fit"
                :disabled="busy || token.trim().length < 20"
            >
                <LoaderCircle v-if="busy" class="animate-spin" aria-hidden="true" />
                {{ t('settings.integrations.facebook.system_user.list') }}
            </button>
        </form>

        <form v-else class="grid gap-2" @submit.prevent="connect">
            <fieldset v-if="pages.length" class="grid gap-1.5">
                <legend class="mb-1 font-semibold">{{ t('settings.integrations.facebook.system_user.choose') }}</legend>
                <label
                    v-for="page in pages"
                    :key="page.id"
                    class="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-md border px-2.5 py-1.5"
                    :class="[
                        selected === page.id ? 'border-primary bg-primary/5' : 'border-border hover:bg-muted/50',
                        page.missing_tasks.length ? 'cursor-not-allowed opacity-60' : '',
                    ]"
                >
                    <input
                        v-model="selected"
                        type="radio"
                        name="system-user-page"
                        :value="page.id"
                        :disabled="page.missing_tasks.length > 0"
                        class="accent-primary"
                    />
                    <img
                        v-if="page.picture"
                        :src="page.picture"
                        alt=""
                        class="size-7 rounded-full object-cover"
                        referrerpolicy="no-referrer"
                        loading="lazy"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{ page.name }}</span>
                        <span v-if="page.missing_tasks.length" class="block text-2xs text-destructive">
                            {{ t('settings.integrations.facebook.system_user.missing', { tasks: missingLabel(page) }) }}
                        </span>
                        <span v-else-if="page.category" class="block truncate text-2xs text-muted-foreground">{{ page.category }}</span>
                    </span>
                </label>
            </fieldset>
            <label v-else class="grid gap-1">
                <span>{{ t('settings.integrations.facebook.system_user.none') }}</span>
                <input
                    v-model="manualPageId"
                    type="text"
                    inputmode="numeric"
                    dir="ltr"
                    required
                    pattern="\d{5,30}"
                    class="h-9 rounded-md border border-input bg-background px-2.5 text-sm"
                    :aria-label="t('settings.integrations.facebook.system_user.page_id')"
                    :placeholder="t('settings.integrations.facebook.system_user.page_id')"
                />
            </label>
            <div class="flex flex-wrap gap-2">
                <button type="submit" :class="buttonVariants({ size: 'sm' })" :disabled="busy || !pageId">
                    <LoaderCircle v-if="busy" class="animate-spin" aria-hidden="true" />
                    {{ t('settings.integrations.facebook.system_user.connect') }}
                </button>
                <button type="button" :class="buttonVariants({ variant: 'ghost', size: 'sm' })" :disabled="busy" @click="pages = null">
                    {{ t('settings.integrations.actions.cancel') }}
                </button>
            </div>
        </form>

        <div v-if="error" role="alert" class="flex items-start gap-2 rounded-md bg-destructive/10 px-2.5 py-2 text-destructive">
            <CircleAlert class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            <div class="min-w-0">
                <p>{{ error.message }}</p>
                <p v-if="error.detail" class="break-words text-2xs opacity-80" dir="ltr">{{ error.detail }}</p>
            </div>
        </div>
    </div>
</template>
