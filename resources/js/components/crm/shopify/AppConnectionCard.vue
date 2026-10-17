<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/format';
import type { ShopifyAppInfo, ShopifyIntegrationRow } from '@/types/admin';
import { computed } from 'vue';

/**
 * Settings → Shopify: how the store is connected (the Partners app or the old manual token), the
 * app token's validity, which secret last verified a webhook, and the two cutover actions
 * (remove the old app's webhooks, roll back to the manual connection). Round 2026-10-17.
 */
const props = defineProps<{
    integration: ShopifyIntegrationRow | null;
    app: ShopifyAppInfo;
    removing: boolean;
    rollingBack: boolean;
    allowing?: boolean;
}>();
const emit = defineEmits<{ removeLegacy: []; rollback: []; allowApp: [] }>();

const { t, locale } = useI18n();

const onApp = computed(() => props.integration?.auth_mode === 'app');
/** Rolled back on purpose: opening the app in Shopify admin will not move the store until allowed again. */
const rolledBack = computed(() => !onApp.value && props.integration?.has_legacy_credentials === true);

/** One line per secret family (all apps' `app:*` labels share one line, the newest wins). */
const verified = computed(() => {
    const newest = (prefix: string) =>
        Object.entries(props.app.last_verified)
            .filter(([label]) => label.startsWith(prefix))
            .map(([, at]) => at)
            .sort()
            .at(-1) ?? null;

    return [
        { key: 'app', at: newest('app:') },
        { key: 'app_prev', at: newest('app_prev:') },
        { key: 'legacy', at: newest('legacy') },
        { key: 'env', at: newest('env') },
    ].filter((row) => row.at !== null || row.key === 'app' || row.key === 'legacy');
});

function confirmThen(message: string, action: () => void): void {
    if (window.confirm(message)) action();
}
</script>

<template>
    <section class="grid gap-3 rounded-lg bg-card p-4 text-xs shadow-card" data-testid="shopify-app-connection">
        <h2 class="text-sm font-semibold">{{ t('settings.shopify.app_conn.title') }}</h2>

        <dl class="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1.5">
            <dt class="text-muted-foreground">{{ t('settings.shopify.app_conn.mode') }}</dt>
            <dd data-testid="shopify-auth-mode">{{ t(`settings.shopify.app_conn.mode_${onApp ? 'app' : 'custom_token'}`) }}</dd>

            <template v-if="onApp">
                <dt class="text-muted-foreground">{{ t('settings.shopify.app_conn.token_valid_until') }}</dt>
                <dd>
                    <span class="tabular-nums">{{ formatDateTime(integration?.access_token_expires_at ?? null, locale) || '—' }}</span>
                    <span class="text-muted-foreground"> · {{ t('settings.shopify.app_conn.token_renews') }}</span>
                </dd>
                <dt class="text-muted-foreground">{{ t('settings.shopify.app_conn.refresh_valid_until') }}</dt>
                <dd class="tabular-nums">{{ formatDateTime(integration?.refresh_token_expires_at ?? null, locale) || '—' }}</dd>
                <dt class="text-muted-foreground">{{ t('settings.shopify.app_conn.installed_at') }}</dt>
                <dd class="tabular-nums">{{ formatDateTime(integration?.installed_at ?? null, locale) || '—' }}</dd>
            </template>
        </dl>

        <p v-if="onApp && integration?.status !== 'connected'" class="rounded bg-warning/10 px-2 py-1.5" data-testid="shopify-app-reopen">
            {{ t('settings.shopify.app_conn.not_connected') }}
        </p>
        <div v-else-if="rolledBack" class="flex flex-wrap items-center gap-2" data-testid="shopify-rolled-back">
            <p class="basis-full text-muted-foreground">{{ t('settings.shopify.app_conn.rolled_back_note') }}</p>
            <Button
                variant="outline"
                class="h-8 rounded-md px-2.5 text-[length:inherit]"
                type="button"
                :loading="allowing"
                data-testid="shopify-allow-app"
                @click="confirmThen(t('settings.shopify.app_conn.allow_app_confirm'), () => emit('allowApp'))"
            >
                {{ t('settings.shopify.app_conn.allow_app') }}
            </Button>
        </div>
        <p v-else-if="!onApp && app.configured" class="text-muted-foreground">{{ t('settings.shopify.app_conn.ready') }}</p>

        <div class="grid gap-1">
            <span class="font-medium">{{ t('settings.shopify.app_conn.last_verified') }}</span>
            <ul class="grid gap-0.5">
                <li v-for="row in verified" :key="row.key" :data-testid="`shopify-verified-${row.key}`">
                    <span class="text-muted-foreground">{{ t(`settings.shopify.app_conn.verified_${row.key}`) }}:</span>
                    <span class="tabular-nums"> {{ formatDateTime(row.at, locale) || t('settings.shopify.app_conn.never') }}</span>
                </li>
            </ul>
        </div>

        <div v-if="onApp" class="flex flex-wrap items-center gap-2">
            <Button
                variant="outline"
                class="h-8 rounded-md px-2.5 text-[length:inherit]"
                type="button"
                :loading="removing"
                data-testid="shopify-remove-legacy"
                @click="confirmThen(t('settings.shopify.app_conn.remove_legacy_confirm'), () => emit('removeLegacy'))"
            >
                {{ t('settings.shopify.app_conn.remove_legacy') }}
            </Button>
            <Button
                variant="outline"
                class="h-8 rounded-md px-2.5 text-[length:inherit]"
                type="button"
                :disabled="!integration?.has_legacy_credentials"
                :loading="rollingBack"
                data-testid="shopify-rollback"
                @click="confirmThen(t('settings.shopify.app_conn.rollback_confirm'), () => emit('rollback'))"
            >
                {{ t('settings.shopify.app_conn.rollback') }}
            </Button>
            <p v-if="!integration?.has_legacy_credentials" class="basis-full text-muted-foreground">{{ t('settings.shopify.app_conn.no_legacy') }}</p>
        </div>
    </section>
</template>
