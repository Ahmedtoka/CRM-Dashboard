<script setup lang="ts">
import StatusChip from '@/components/crm/StatusChip.vue';
import SettingsPanel from '@/components/crm/settings/SettingsPanel.vue';
import { Button } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import type { ReadinessCheck, ReadinessReport } from '@/types/whatsappReadiness';
import { CircleCheck, CircleHelp, CircleX, RefreshCw } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

/**
 * «جاهزية تأكيد الأوردرات بالواتساب» (round 2026-10-24): what the system can see about the number the order
 * confirmations will go from, the approval of its templates on Meta, the test send to the admin's own number, and the
 * steps the owner does in Meta. Admin only (the endpoints are under /settings).
 */
const { t } = useI18n();
const api = useApi();
const toast = useToast();

const report = ref<ReadinessReport | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);
const phone = ref('');
const sending = ref(false);
const testError = ref<string | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get<{ data: ReadinessReport }>('/settings/whatsapp-readiness');
        report.value = data.data;
    } catch (e) {
        loadError.value = apiErrorMessage(e, t('common.error'));
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function value(check: ReadinessCheck): string {
    if (check.key === 'account') {
        return check.ok ? (check.detail ?? '') : t(`wa_ready.reasons.${check.reason ?? 'none'}`);
    }
    if (check.key === 'templates') {
        if (check.ok === null) return t(`wa_ready.template_errors.${check.detail ?? 'graph_error'}`);
        return (report.value?.templates ?? []).map((x) => `${x.name}: ${t(`wa_ready.template_status.${x.status}`)}`).join(' · ');
    }
    if (check.key === 'token') return check.ok ? t('wa_ready.present') : t('wa_ready.missing');
    if (check.key === 'enabled') return check.ok ? t('wa_ready.on') : t('wa_ready.off');
    if (check.key === 'environment') return check.detail ?? '';
    return check.detail ?? (check.ok ? t('wa_ready.present') : t('wa_ready.missing'));
}

const checks = computed(() => report.value?.checks ?? []);

async function sendTest(): Promise<void> {
    sending.value = true;
    testError.value = null;
    try {
        const { data } = await api.post<{ ok: boolean; order: string | null; phone: string }>('/settings/whatsapp-readiness/test', {
            phone: phone.value,
        });
        toast.push(t('wa_ready.test.sent', { phone: data.phone, order: data.order ?? '' }));
    } catch (e) {
        const body = (e as { response?: { data?: { error?: string } } }).response?.data;
        const code = body?.error ?? '';
        const known = ['invalid_phone', 'customer_number', 'no_whatsapp_account', 'no_order'];
        testError.value = known.includes(code)
            ? t(`wa_ready.test.errors.${code}`)
            : t('wa_ready.test.errors.failed', { error: code || apiErrorMessage(e, t('common.error')) });
    } finally {
        sending.value = false;
    }
}

const steps = ['s1', 's2', 's3', 's4', 's5', 's6', 's7'];
</script>

<template>
    <SettingsPanel :title="t('wa_ready.title')" :description="t('wa_ready.description')" data-wa-readiness>
        <template #actions>
            <StatusChip
                v-if="report"
                :label="report.ready ? t('wa_ready.ready') : t('wa_ready.not_ready')"
                :tone="report.ready ? 'ok' : 'warn'"
                dot
                data-wa-ready-chip
            />
            <Button type="button" variant="outline" size="sm" :loading="loading" @click="load"
                ><RefreshCw aria-hidden="true" />{{ t('wa_ready.refresh') }}</Button
            >
        </template>

        <p v-if="loadError" role="alert" class="py-3 text-13 text-danger">{{ loadError }}</p>

        <ul v-if="report" class="divide-y divide-line">
            <li v-for="c in checks" :key="c.key" class="flex items-start gap-3 py-3 text-13" :data-wa-check="c.key" :data-ok="String(c.ok)">
                <CircleCheck v-if="c.ok === true" class="mt-0.5 size-4 shrink-0 text-ok" aria-hidden="true" />
                <CircleX v-else-if="c.ok === false" class="mt-0.5 size-4 shrink-0 text-danger" aria-hidden="true" />
                <CircleHelp v-else class="mt-0.5 size-4 shrink-0 text-fg-3" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">{{ t(`wa_ready.checks.${c.key}`) }}</p>
                    <p class="break-words text-fg-2" dir="auto">{{ value(c) }}</p>
                </div>
            </li>
        </ul>

        <div v-if="report && report.templates.some((x) => x.status !== 'APPROVED')" class="border-t border-line py-3 text-xs text-fg-2">
            {{ t('wa_ready.submit_hint') }}
        </div>

        <form class="grid gap-2 border-t border-line py-4" data-wa-test @submit.prevent="sendTest">
            <h3 class="text-sm font-bold">{{ t('wa_ready.test.title') }}</h3>
            <p class="text-13 text-fg-2">{{ t('wa_ready.test.hint') }}</p>
            <div class="flex flex-wrap items-end gap-2">
                <label class="grid gap-1">
                    <span class="text-xs font-semibold text-fg-2">{{ t('wa_ready.test.phone') }}</span>
                    <input
                        v-model="phone"
                        type="tel"
                        inputmode="tel"
                        placeholder="01xxxxxxxxx"
                        dir="ltr"
                        class="h-10 w-52 rounded-md border border-line-strong bg-card px-3 text-sm tabular-nums"
                        data-wa-test-phone
                        required
                    />
                </label>
                <Button type="submit" :loading="sending" :disabled="!report?.account" data-wa-test-send>{{ t('wa_ready.test.send') }}</Button>
            </div>
            <p v-if="testError" role="alert" class="text-xs text-danger" data-wa-test-error>{{ testError }}</p>
        </form>

        <details class="border-t border-line py-3 text-13">
            <summary class="cursor-pointer font-semibold">{{ t('wa_ready.steps.title') }}</summary>
            <ol class="mt-2 list-decimal space-y-1.5 ps-5 text-fg-2">
                <li v-for="s in steps" :key="s">{{ t(`wa_ready.steps.${s}`) }}</li>
            </ol>
        </details>
    </SettingsPanel>
</template>
