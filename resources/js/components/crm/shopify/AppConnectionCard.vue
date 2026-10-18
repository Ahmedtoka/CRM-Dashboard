<script setup lang="ts">
import SettingsPanel from '@/components/crm/settings/SettingsPanel.vue';
import SettingsRow from '@/components/crm/settings/SettingsRow.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
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

/** One state in words for the panel header: where the store stands with the app right now. */
const state = computed(() => {
    if (onApp.value)
        return props.integration?.status === 'connected' ? { key: 'on_app', tone: 'ok' as const } : { key: 'reopen', tone: 'warn' as const };
    if (rolledBack.value) return { key: 'rolled_back', tone: 'neutral' as const };
    return props.app.configured ? { key: 'ready', tone: 'info' as const } : { key: 'not_configured', tone: 'neutral' as const };
});

function confirmThen(message: string, action: () => void): void {
    if (window.confirm(message)) action();
}
</script>

<template>
    <SettingsPanel
        :title="t('settings.shopify.app_conn.title')"
        :description="t('settings.shopify.app_conn.what')"
        data-testid="shopify-app-connection"
    >
        <template #actions>
            <StatusChip :label="t(`settings.shopify.app_conn.state.${state.key}`)" :tone="state.tone" data-testid="shopify-app-state" />
        </template>

        <SettingsRow :title="t('settings.shopify.app_conn.mode')">
            <span class="text-13 text-fg-2" data-testid="shopify-auth-mode">{{
                t(`settings.shopify.app_conn.mode_${onApp ? 'app' : 'custom_token'}`)
            }}</span>
        </SettingsRow>

        <template v-if="onApp">
            <SettingsRow :title="t('settings.shopify.app_conn.token_valid_until')" :description="t('settings.shopify.app_conn.token_renews')">
                <span class="text-13 tabular-nums">{{ formatDateTime(integration?.access_token_expires_at ?? null, locale) || '—' }}</span>
            </SettingsRow>
            <SettingsRow :title="t('settings.shopify.app_conn.refresh_valid_until')">
                <span class="text-13 tabular-nums">{{ formatDateTime(integration?.refresh_token_expires_at ?? null, locale) || '—' }}</span>
            </SettingsRow>
            <SettingsRow :title="t('settings.shopify.app_conn.installed_at')">
                <span class="text-13 tabular-nums">{{ formatDateTime(integration?.installed_at ?? null, locale) || '—' }}</span>
            </SettingsRow>
        </template>

        <div v-if="onApp && integration?.status !== 'connected'" class="py-3">
            <p class="rounded-md bg-warn-soft px-3 py-2 text-13 text-foreground" data-testid="shopify-app-reopen">
                {{ t('settings.shopify.app_conn.not_connected') }}
            </p>
        </div>
        <SettingsRow
            v-else-if="rolledBack"
            :title="t('settings.shopify.app_conn.state.rolled_back')"
            :description="t('settings.shopify.app_conn.rolled_back_note')"
            data-testid="shopify-rolled-back"
        >
            <Button
                variant="outline"
                size="sm"
                class="max-sm:h-10"
                type="button"
                :loading="allowing"
                data-testid="shopify-allow-app"
                @click="confirmThen(t('settings.shopify.app_conn.allow_app_confirm'), () => emit('allowApp'))"
            >
                {{ t('settings.shopify.app_conn.allow_app') }}
            </Button>
        </SettingsRow>
        <div v-else-if="!onApp" class="py-3">
            <p class="rounded-md bg-sunken px-3 py-2 text-13 text-fg-2">
                {{ app.configured ? t('settings.shopify.app_conn.ready') : t('settings.shopify.app_conn.not_configured') }}
            </p>
        </div>

        <SettingsRow :title="t('settings.shopify.app_conn.last_verified')" stack>
            <template #below>
                <ul class="mt-1.5 grid gap-1 text-13">
                    <li v-for="row in verified" :key="row.key" class="flex flex-wrap gap-x-2" :data-testid="`shopify-verified-${row.key}`">
                        <span class="text-fg-2">{{ t(`settings.shopify.app_conn.verified_${row.key}`) }}:</span>
                        <span class="tabular-nums">{{ formatDateTime(row.at, locale) || t('settings.shopify.app_conn.never') }}</span>
                    </li>
                </ul>
            </template>
        </SettingsRow>

        <SettingsRow
            v-if="onApp"
            :title="t('settings.shopify.app_conn.cutover')"
            :description="
                integration?.has_legacy_credentials ? t('settings.shopify.app_conn.cutover_hint') : t('settings.shopify.app_conn.no_legacy')
            "
        >
            <Button
                variant="outline"
                size="sm"
                class="max-sm:h-10"
                type="button"
                :loading="removing"
                data-testid="shopify-remove-legacy"
                @click="confirmThen(t('settings.shopify.app_conn.remove_legacy_confirm'), () => emit('removeLegacy'))"
            >
                {{ t('settings.shopify.app_conn.remove_legacy') }}
            </Button>
            <Button
                variant="ghost"
                size="sm"
                class="max-sm:h-10"
                type="button"
                :disabled="!integration?.has_legacy_credentials"
                :loading="rollingBack"
                data-testid="shopify-rollback"
                @click="confirmThen(t('settings.shopify.app_conn.rollback_confirm'), () => emit('rollback'))"
            >
                {{ t('settings.shopify.app_conn.rollback') }}
            </Button>
        </SettingsRow>
    </SettingsPanel>
</template>
