<script setup lang="ts">
import StatusChip from '@/components/crm/StatusChip.vue';
import StickySaveBar from '@/components/crm/StickySaveBar.vue';
import ToggleSwitch from '@/components/crm/ToggleSwitch.vue';
import SettingsPanel from '@/components/crm/settings/SettingsPanel.vue';
import SettingsRow from '@/components/crm/settings/SettingsRow.vue';
import SettingsShell from '@/components/crm/settings/SettingsShell.vue';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/composables/useI18n';
import { useSettingsBreadcrumbs } from '@/composables/useSettingsBreadcrumbs';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount } from '@/lib/format';
import type { ConfirmationSettingsData, WhatsAppChannelState } from '@/types/orderConfirmations';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';

/** «تأكيد الأوردرات على الواتساب» (spec 2026-10-16): admin only; ConfirmationSettings plus the WhatsApp number's state. */
const props = defineProps<{
    settings: ConfirmationSettingsData;
    whatsapp: WhatsAppChannelState | null;
    limits: { delay_max: number; reminder_max: number; no_response_max: number };
}>();

const { t, locale } = useI18n();

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

/** The timing as one sentence, read back from the fields as they are typed. */
const timeline = computed(() =>
    t('order_confirmations.settings.timeline', {
        delay: formatCount(Number(form.delay_minutes) || 0, locale.value),
        reminder: formatCount(Number(form.reminder_after_hours) || 0, locale.value),
        none: formatCount(Number(form.no_response_after_hours) || 0, locale.value),
    }),
);

const title = computed(() => t('settings_hub.items.order_confirmations'));
const breadcrumbs = useSettingsBreadcrumbs(() => title.value);
const num = 'h-10 w-28 tabular-nums';
const text = 'h-10 w-full sm:w-60';
const error = 'mt-1 text-12 text-danger';
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsShell :title="title" :description="t('order_confirmations.settings.description')" width="form">
            <!-- The WhatsApp number the messages go from: read-only here, connected on «الربط». -->
            <SettingsPanel data-whatsapp-state>
                <SettingsRow :title="t('order_confirmations.settings.channel')">
                    <template v-if="whatsapp" #below>
                        <p class="mt-0.5 text-13 text-fg-2">
                            <span dir="auto">{{ whatsapp.name }}</span>
                            <bdi v-if="whatsapp.phone" class="ms-2 tabular-nums" dir="ltr">{{ whatsapp.phone }}</bdi>
                        </p>
                    </template>
                    <StatusChip
                        :label="t(whatsapp?.connected ? 'order_confirmations.settings.connected' : 'order_confirmations.settings.not_connected')"
                        :tone="whatsapp?.connected ? 'ok' : 'danger'"
                        dot
                    />
                </SettingsRow>
                <div v-if="!whatsapp?.connected" class="mb-3 flex items-start gap-2 rounded-md bg-warn-soft p-3 text-13 text-foreground" role="alert">
                    <TriangleAlert class="mt-0.5 size-4 shrink-0 text-warn" aria-hidden="true" />
                    <p>
                        {{ t('order_confirmations.settings.no_channel') }}
                        <Link href="/settings/integrations" class="font-semibold text-brand-ink underline">{{
                            t('order_confirmations.settings.open_integrations')
                        }}</Link>
                    </p>
                </div>
            </SettingsPanel>

            <form class="space-y-5" @submit.prevent="submit">
                <SettingsPanel>
                    <SettingsRow
                        for="oc-enabled"
                        :title="t('order_confirmations.settings.enabled')"
                        :description="t('order_confirmations.settings.enabled_hint')"
                    >
                        <ToggleSwitch id="oc-enabled" v-model="form.enabled" :label="t('order_confirmations.settings.enabled')" />
                    </SettingsRow>
                    <SettingsRow
                        for="oc-skip-chat"
                        :title="t('order_confirmations.settings.skip_chat_orders')"
                        :description="t('order_confirmations.settings.skip_chat_orders_hint')"
                    >
                        <ToggleSwitch id="oc-skip-chat" v-model="form.skip_chat_orders" :label="t('order_confirmations.settings.skip_chat_orders')" />
                    </SettingsRow>
                </SettingsPanel>

                <SettingsPanel :title="t('order_confirmations.settings.timing')" :description="timeline">
                    <SettingsRow
                        for="oc-delay"
                        :title="t('order_confirmations.settings.delay_minutes')"
                        :description="t('order_confirmations.settings.delay_hint', { max: limits.delay_max })"
                    >
                        <template v-if="form.errors.delay_minutes" #below>
                            <p :class="error">{{ form.errors.delay_minutes }}</p>
                        </template>
                        <Input id="oc-delay" v-model="form.delay_minutes" type="number" min="0" :max="limits.delay_max" dir="ltr" :class="num" />
                    </SettingsRow>
                    <SettingsRow
                        for="oc-age"
                        :title="t('order_confirmations.settings.max_order_age_minutes')"
                        :description="t('order_confirmations.settings.max_order_age_hint')"
                    >
                        <template v-if="form.errors.max_order_age_minutes" #below>
                            <p :class="error">{{ form.errors.max_order_age_minutes }}</p>
                        </template>
                        <Input id="oc-age" v-model="form.max_order_age_minutes" type="number" min="10" max="1440" dir="ltr" :class="num" />
                    </SettingsRow>
                    <SettingsRow
                        for="oc-reminder"
                        :title="t('order_confirmations.settings.reminder_after_hours')"
                        :description="t('order_confirmations.settings.reminder_hint', { max: limits.reminder_max })"
                    >
                        <template v-if="form.errors.reminder_after_hours" #below>
                            <p :class="error">{{ form.errors.reminder_after_hours }}</p>
                        </template>
                        <Input
                            id="oc-reminder"
                            v-model="form.reminder_after_hours"
                            type="number"
                            min="1"
                            :max="limits.reminder_max"
                            dir="ltr"
                            :class="num"
                        />
                    </SettingsRow>
                    <SettingsRow
                        for="oc-no-response"
                        :title="t('order_confirmations.settings.no_response_after_hours')"
                        :description="t('order_confirmations.settings.no_response_hint', { max: limits.no_response_max })"
                    >
                        <template v-if="form.errors.no_response_after_hours" #below>
                            <p :class="error">{{ form.errors.no_response_after_hours }}</p>
                        </template>
                        <Input
                            id="oc-no-response"
                            v-model="form.no_response_after_hours"
                            type="number"
                            :min="Number(form.reminder_after_hours) + 1"
                            :max="limits.no_response_max"
                            dir="ltr"
                            :class="num"
                        />
                    </SettingsRow>
                </SettingsPanel>

                <SettingsPanel :title="t('order_confirmations.settings.templates')" :description="t('order_confirmations.settings.templates_hint')">
                    <SettingsRow for="oc-template-confirm" :title="t('order_confirmations.settings.template_confirm')">
                        <template v-if="form.errors.template_confirm" #below>
                            <p :class="error">{{ form.errors.template_confirm }}</p>
                        </template>
                        <Input id="oc-template-confirm" v-model="form.template_confirm" dir="ltr" maxlength="512" :class="text" />
                    </SettingsRow>
                    <SettingsRow for="oc-template-reminder" :title="t('order_confirmations.settings.template_reminder')">
                        <template v-if="form.errors.template_reminder" #below>
                            <p :class="error">{{ form.errors.template_reminder }}</p>
                        </template>
                        <Input id="oc-template-reminder" v-model="form.template_reminder" dir="ltr" maxlength="512" :class="text" />
                    </SettingsRow>
                    <SettingsRow for="oc-template-language" :title="t('order_confirmations.settings.template_language')">
                        <template v-if="form.errors.template_language" #below>
                            <p :class="error">{{ form.errors.template_language }}</p>
                        </template>
                        <Input id="oc-template-language" v-model="form.template_language" dir="ltr" maxlength="15" :class="num" />
                    </SettingsRow>
                </SettingsPanel>

                <StickySaveBar :bleed="false" :busy="form.processing" :dirty="form.isDirty" :label="t('order_confirmations.settings.save')" submit />
            </form>
        </SettingsShell>
    </AppLayout>
</template>
