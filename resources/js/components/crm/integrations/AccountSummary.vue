<script setup lang="ts">
import { buttonVariants } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useNow } from '@/composables/useNow';
import { formatDateTime, formatSince } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { HealthCheckItem, IntegrationAccount } from '@/types/admin';
import { CircleAlert, CircleCheck, LoaderCircle, RefreshCw, TriangleAlert, Unplug } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * The connected / problem body of an Integrations card: when it was connected, the
 * last customer message, what the last health check found (problems first, each with
 * its one-click fix), and Test / Reconnect / Disconnect.
 */
const props = defineProps<{
    account: IntegrationAccount;
    testing: boolean;
    fixing: boolean;
    reconnecting?: boolean;
}>();
const emit = defineEmits<{ test: []; reconnect: []; disconnect: []; fix: [action: NonNullable<HealthCheckItem['fix']>] }>();

const { t, locale } = useI18n();
const now = useNow();

const rank = { problem: 0, warning: 1, ok: 2 } as const;
const checks = computed(() => [...(props.account.health?.checks ?? [])].sort((a, b) => rank[a.status] - rank[b.status]));
const icons = { ok: CircleCheck, warning: TriangleAlert, problem: CircleAlert } as const;
const iconClass = { ok: 'text-success', warning: 'text-warning', problem: 'text-destructive' } as const;

/** ISO details are dates (token expiry, last message) and read better formatted. */
function isIso(value: string | undefined): value is string {
    return !!value && /^\d{4}-\d{2}-\d{2}T/.test(value);
}

function text(check: HealthCheckItem): string {
    return t(`settings.integrations.codes.${check.code}`, { missing: (check.missing ?? []).join(', '), detail: check.detail ?? '' });
}

function detail(check: HealthCheckItem): string | null {
    if (!check.detail || text(check).includes(check.detail)) return null;
    if (isIso(check.detail)) {
        return check.key === 'inbound' ? formatSince(check.detail, locale.value, now.value) : formatDateTime(check.detail, locale.value);
    }
    return check.detail;
}

const lastMessage = computed(() =>
    props.account.last_inbound_at ? formatSince(props.account.last_inbound_at, locale.value, now.value) : t('settings.integrations.none_yet'),
);
</script>

<template>
    <div class="grid gap-3">
        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-xs sm:grid-cols-3">
            <div class="min-w-0">
                <dt class="text-muted-foreground">{{ t('settings.integrations.connected_since') }}</dt>
                <dd class="truncate font-medium tabular-nums">{{ formatDateTime(account.connected_at, locale) || '—' }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-muted-foreground">{{ t('settings.integrations.last_message') }}</dt>
                <dd class="truncate font-medium tabular-nums">{{ lastMessage }}</dd>
            </div>
            <slot name="facts" />
        </dl>

        <ul v-if="checks.length" class="grid gap-1.5 rounded-md bg-muted/50 p-2.5 text-xs" :aria-label="t('settings.integrations.last_check')">
            <li v-for="check in checks" :key="check.key" class="flex flex-wrap items-start gap-x-2 gap-y-1">
                <component :is="icons[check.status]" class="mt-0.5 size-3.5 shrink-0" :class="iconClass[check.status]" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <span class="font-medium">{{ t(`settings.integrations.checks.${check.key}`) }}:</span>
                    {{ ' ' }}
                    <span :class="check.status === 'problem' ? 'text-destructive' : 'text-foreground'">{{ text(check) }}</span>
                    <span v-if="detail(check)" class="block break-words text-2xs text-muted-foreground" dir="auto">{{ detail(check) }}</span>
                </div>
                <button
                    v-if="check.fix && check.status !== 'ok'"
                    type="button"
                    :class="cn(buttonVariants({ variant: check.status === 'problem' ? 'default' : 'outline', size: 'sm' }), 'h-7')"
                    :disabled="fixing || reconnecting"
                    @click="check.fix === 'resubscribe' ? emit('fix', check.fix) : emit('reconnect')"
                >
                    <LoaderCircle v-if="fixing && check.fix === 'resubscribe'" class="animate-spin" aria-hidden="true" />
                    {{ t(`settings.integrations.fixes.${check.fix}`) }}
                </button>
            </li>
            <li v-if="account.health_checked_at" class="text-2xs text-muted-foreground">
                {{ t('settings.integrations.last_check') }}: {{ formatSince(account.health_checked_at, locale, now) }}
            </li>
        </ul>

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" :class="buttonVariants({ variant: 'outline', size: 'sm' })" :disabled="testing" @click="emit('test')">
                <LoaderCircle v-if="testing" class="animate-spin" aria-hidden="true" />
                <CircleCheck v-else aria-hidden="true" />
                {{ t('settings.integrations.actions.test') }}
            </button>
            <button type="button" :class="buttonVariants({ variant: 'outline', size: 'sm' })" :disabled="reconnecting" @click="emit('reconnect')">
                <LoaderCircle v-if="reconnecting" class="animate-spin" aria-hidden="true" />
                <RefreshCw v-else aria-hidden="true" />
                {{ t('settings.integrations.actions.reconnect') }}
            </button>
            <button
                type="button"
                :class="
                    cn(buttonVariants({ variant: 'ghost', size: 'sm' }), 'ms-auto text-destructive hover:bg-destructive/10 hover:text-destructive')
                "
                @click="emit('disconnect')"
            >
                <Unplug aria-hidden="true" />
                {{ t('settings.integrations.actions.disconnect') }}
            </button>
        </div>
    </div>
</template>
