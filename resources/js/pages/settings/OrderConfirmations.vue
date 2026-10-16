<script setup lang="ts">
import Callout from '@/components/crm/Callout.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import StickySaveBar from '@/components/crm/StickySaveBar.vue';
import ToggleSwitch from '@/components/crm/ToggleSwitch.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ConfirmationSettingsData, WhatsAppChannelState } from '@/types/orderConfirmations';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

/** «تأكيد الأوردرات على الواتساب» (spec 2026-10-16): admin only; ConfirmationSettings plus the WhatsApp number's state. */
const props = defineProps<{
    settings: ConfirmationSettingsData;
    whatsapp: WhatsAppChannelState | null;
    limits: { delay_max: number; reminder_max: number; no_response_max: number };
}>();

const { t } = useI18n();

// A type alias, not the interface: Inertia's form data constraint needs an index signature.
type Form = { [K in keyof ConfirmationSettingsData]: ConfirmationSettingsData[K] };
const form = useForm<Form>({ ...props.settings });

const NUMERIC = ['delay_minutes', 'reminder_after_hours', 'no_response_after_hours', 'max_order_age_minutes'] as const;

// The wrapped <Input> hands numbers back as strings: coerce before sending.
function submit(): void {
    form.transform((data) => {
        const payload: Record<string, unknown> = { ...data };
        for (const key of NUMERIC) payload[key] = Number(payload[key]);
        return payload;
    }).put('/settings/order-confirmations', { preserveScroll: true });
}

const breadcrumbs = computed(() => [{ title: t('order_confirmations.settings.title'), href: '/settings/order-confirmations' }]);
const input = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm tabular-nums';
const card = 'space-y-4 rounded-lg bg-card p-4 shadow-card';
const grid = 'grid gap-3 sm:grid-cols-2';
const hint = 'text-2xs text-muted-foreground';
const error = 'text-2xs text-destructive';
</script>

<template>
    <Head :title="t('order_confirmations.settings.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-3xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('order_confirmations.settings.title')" :description="t('order_confirmations.settings.description')" />

            <!-- The WhatsApp number the messages go from: read-only here, connected on «الربط». -->
            <section :class="card" data-whatsapp-state>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold">{{ t('order_confirmations.settings.channel') }}</h2>
                    <StatusChip
                        :label="t(whatsapp?.connected ? 'order_confirmations.settings.connected' : 'order_confirmations.settings.not_connected')"
                        :tone="whatsapp?.connected ? 'positive' : 'negative'"
                        dot
                    />
                </div>
                <p v-if="whatsapp" class="text-xs">
                    <span dir="auto">{{ whatsapp.name }}</span>
                    <span v-if="whatsapp.phone" class="ms-2 text-muted-foreground" dir="ltr">{{ whatsapp.phone }}</span>
                </p>
                <Callout v-if="!whatsapp?.connected" tone="warning">
                    {{ t('order_confirmations.settings.no_channel') }}
                    <Link href="/settings/integrations" class="font-medium underline">{{ t('order_confirmations.settings.open_integrations') }}</Link>
                </Callout>
            </section>

            <form class="space-y-4" @submit.prevent="submit">
                <section :class="card">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <Label for="oc-enabled">{{ t('order_confirmations.settings.enabled') }}</Label>
                            <p :class="hint">{{ t('order_confirmations.settings.enabled_hint') }}</p>
                        </div>
                        <ToggleSwitch id="oc-enabled" v-model="form.enabled" :label="t('order_confirmations.settings.enabled')" />
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <Label for="oc-skip-chat">{{ t('order_confirmations.settings.skip_chat_orders') }}</Label>
                            <p :class="hint">{{ t('order_confirmations.settings.skip_chat_orders_hint') }}</p>
                        </div>
                        <ToggleSwitch id="oc-skip-chat" v-model="form.skip_chat_orders" :label="t('order_confirmations.settings.skip_chat_orders')" />
                    </div>
                </section>

                <section :class="card">
                    <h2 class="text-sm font-semibold">{{ t('order_confirmations.settings.timing') }}</h2>
                    <div :class="grid">
                        <label class="grid content-start gap-1">
                            <Label>{{ t('order_confirmations.settings.delay_minutes') }}</Label>
                            <Input v-model="form.delay_minutes" type="number" min="0" :max="limits.delay_max" dir="ltr" :class="input" />
                            <span :class="hint">{{ t('order_confirmations.settings.delay_hint', { max: limits.delay_max }) }}</span>
                            <span v-if="form.errors.delay_minutes" :class="error">{{ form.errors.delay_minutes }}</span>
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('order_confirmations.settings.max_order_age_minutes') }}</Label>
                            <Input v-model="form.max_order_age_minutes" type="number" min="10" max="1440" dir="ltr" :class="input" />
                            <span :class="hint">{{ t('order_confirmations.settings.max_order_age_hint') }}</span>
                            <span v-if="form.errors.max_order_age_minutes" :class="error">{{ form.errors.max_order_age_minutes }}</span>
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('order_confirmations.settings.reminder_after_hours') }}</Label>
                            <Input v-model="form.reminder_after_hours" type="number" min="1" :max="limits.reminder_max" dir="ltr" :class="input" />
                            <span :class="hint">{{ t('order_confirmations.settings.reminder_hint', { max: limits.reminder_max }) }}</span>
                            <span v-if="form.errors.reminder_after_hours" :class="error">{{ form.errors.reminder_after_hours }}</span>
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('order_confirmations.settings.no_response_after_hours') }}</Label>
                            <Input
                                v-model="form.no_response_after_hours"
                                type="number"
                                :min="Number(form.reminder_after_hours) + 1"
                                :max="limits.no_response_max"
                                dir="ltr"
                                :class="input"
                            />
                            <span :class="hint">{{ t('order_confirmations.settings.no_response_hint', { max: limits.no_response_max }) }}</span>
                            <span v-if="form.errors.no_response_after_hours" :class="error">{{ form.errors.no_response_after_hours }}</span>
                        </label>
                    </div>
                </section>

                <section :class="card">
                    <h2 class="text-sm font-semibold">{{ t('order_confirmations.settings.templates') }}</h2>
                    <p :class="hint">{{ t('order_confirmations.settings.templates_hint') }}</p>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="grid content-start gap-1">
                            <Label>{{ t('order_confirmations.settings.template_confirm') }}</Label>
                            <Input v-model="form.template_confirm" dir="ltr" maxlength="512" :class="input" />
                            <span v-if="form.errors.template_confirm" :class="error">{{ form.errors.template_confirm }}</span>
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('order_confirmations.settings.template_reminder') }}</Label>
                            <Input v-model="form.template_reminder" dir="ltr" maxlength="512" :class="input" />
                            <span v-if="form.errors.template_reminder" :class="error">{{ form.errors.template_reminder }}</span>
                        </label>
                        <label class="grid content-start gap-1">
                            <Label>{{ t('order_confirmations.settings.template_language') }}</Label>
                            <Input v-model="form.template_language" dir="ltr" maxlength="15" :class="input" />
                            <span v-if="form.errors.template_language" :class="error">{{ form.errors.template_language }}</span>
                        </label>
                    </div>
                </section>

                <StickySaveBar :busy="form.processing" :dirty="form.isDirty" :label="t('order_confirmations.settings.save')" submit />
            </form>
        </div>
    </AppLayout>
</template>
