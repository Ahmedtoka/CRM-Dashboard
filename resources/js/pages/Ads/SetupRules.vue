<script setup lang="ts">
/** «الإعداد › القواعد»: tax rate and winner/loser thresholds (moved from BuyersSetup). S5 adds break-even and rules. */
import AdsSetupTabs from '@/components/ads/AdsSetupTabs.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import type { AdsSetupSettings, AdsWinnerThresholds } from '@/types/ads';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ settings: AdsSetupSettings; launchExpiryDays: number }>();
const { t } = useI18n();
const toast = useToast();
const num = (v: number | null | undefined) => (v === null || v === undefined ? '' : String(v));
const THRESHOLD_KEYS: (keyof AdsWinnerThresholds)[] = ['winner', 'promising', 'loser', 'loser_min_spend', 'min_spend', 'min_days'];
const form = useForm({
    tax_rate_percent: num(props.settings.tax_rate_percent),
    launch_expiry_days: String(props.launchExpiryDays),
    winner_thresholds: Object.fromEntries(THRESHOLD_KEYS.map((k) => [k, num(props.settings.winner_thresholds[k])])) as Record<keyof AdsWinnerThresholds, string>,
});
const err = (key: string) => (form.errors as Record<string, string | undefined>)[key];
const step = (key: keyof AdsWinnerThresholds) => (key === 'min_days' || key.endsWith('spend') ? '1' : '0.01');
const input = 'flex h-11 w-full rounded-md border border-input bg-card px-3 text-sm md:h-9';
const save = () => form.put('/ads/setup/settings', { preserveScroll: true, onSuccess: () => toast.push(t('ads.setup.saved')) });
const crumbs = computed(() => [{ label: t('nav.ads_setup'), href: '/ads/setup' }, { label: t('ads.control.setup.rules') }]);
const appCrumbs = computed(() => [
    { title: t('nav.ads_setup'), href: '/ads/setup' },
    { title: t('ads.control.setup.rules'), href: '/ads/setup/rules' },
]);
</script>

<template>
    <Head :title="t('ads.control.setup.rules_title')" />
    <AppLayout :breadcrumbs="appCrumbs">
        <div class="mx-auto w-full min-w-0 max-w-5xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('ads.control.setup.rules_title')" :description="t('ads.control.setup.rules_description')" :breadcrumbs="crumbs" />
            <AdsSetupTabs />
            <form class="space-y-5 rounded-lg bg-card p-4 shadow-card" :aria-label="t('ads.control.setup.rules_title')" @submit.prevent="save">
                <div class="max-w-xs space-y-1">
                    <label class="text-xs font-medium" for="tax-rate">{{ t('ads.setup.tax_rate') }} (%)</label>
                    <input id="tax-rate" v-model="form.tax_rate_percent" type="number" min="0" max="100" step="0.01" inputmode="decimal" dir="ltr" :class="input" />
                    <p class="text-2xs text-muted-foreground">{{ t('ads.setup.tax_help') }}</p>
                    <p v-if="err('tax_rate_percent')" class="text-2xs text-destructive">{{ err('tax_rate_percent') }}</p>
                </div>
                <div class="max-w-xs space-y-1">
                    <label class="text-xs font-medium" for="launch-expiry">{{ t('ads.setup.launch_expiry_days') }}</label>
                    <input id="launch-expiry" v-model="form.launch_expiry_days" type="number" min="1" max="30" step="1" inputmode="numeric" dir="ltr" :class="input" />
                    <p class="text-2xs text-muted-foreground">{{ t('ads.setup.launch_expiry_hint') }}</p>
                    <p v-if="err('launch_expiry_days')" class="text-2xs text-destructive">{{ err('launch_expiry_days') }}</p>
                </div>
                <div class="space-y-3">
                    <h2 class="text-xs font-semibold">{{ t('ads.setup.thresholds_title') }}</h2>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="key in THRESHOLD_KEYS" :key="key" class="space-y-1">
                            <label class="text-xs font-medium" :for="`th-${key}`">{{ t(`ads.setup.${key}`) }}</label>
                            <input
                                :id="`th-${key}`"
                                v-model="form.winner_thresholds[key]"
                                type="number"
                                :min="key === 'min_days' ? 1 : 0"
                                :max="key === 'min_days' ? 30 : undefined"
                                :step="step(key)"
                                inputmode="decimal"
                                dir="ltr"
                                :class="input"
                            />
                            <p class="text-2xs text-muted-foreground">{{ t(`ads.setup.${key}_help`) }}</p>
                            <p v-if="err(`winner_thresholds.${key}`)" class="text-2xs text-destructive">{{ err(`winner_thresholds.${key}`) }}</p>
                        </div>
                    </div>
                </div>
                <Button type="submit" :loading="form.processing">{{ t('common.save') }}</Button>
            </form>
        </div>
    </AppLayout>
</template>
