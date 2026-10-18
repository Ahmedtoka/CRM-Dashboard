<script setup lang="ts">
/** «ابدأ من هنا» (owner, 2026-09-26): the admin's connect-everything checklist, read from what is really connected. */
import StatusChip from '@/components/crm/StatusChip.vue';
import SettingsShell from '@/components/crm/settings/SettingsShell.vue';
import { Button, buttonVariants } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useSettingsBreadcrumbs } from '@/composables/useSettingsBreadcrumbs';
import { useVisitLoading } from '@/composables/useVisitLoading';
import { formatNumber } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, CircleCheck } from 'lucide-vue-next';
import { computed } from 'vue';

interface Step {
    key: 'facebook' | 'instagram' | 'whatsapp' | 'shopify' | 'store_info' | 'bot' | 'test_link';
    done: boolean;
    href: string;
    external: boolean;
    detail: string | null;
    blocked_by: string | null;
    required: boolean;
}

interface Progress {
    steps: Step[];
    done: number;
    total: number;
    percent: number;
    complete: boolean;
    dismissed: boolean;
}

const props = defineProps<{ progress: Progress }>();

const { t, locale } = useI18n();

const next = computed(() => props.progress.steps.find((s) => !s.done && !s.blocked_by) ?? null);
const allDone = computed(() => props.progress.done === props.progress.total);
const connectKeys = ['facebook', 'instagram', 'whatsapp', 'shopify'];
const ctaLabel = (step: Step) =>
    step.done ? t('onboarding.open') : connectKeys.includes(step.key) ? t('onboarding.connect') : t('onboarding.start');
/** One primary per area: the next step to do is mauve, the later ones outlined. */
const ctaVariant = (step: Step) => (step.done ? 'ghost' : next.value?.key === step.key ? 'default' : 'outline');
const num = (n: number) => formatNumber(locale.value, n);

function detailOf(step: Step): string | null {
    if (!step.detail) return null;
    if (step.key === 'store_info') return t('onboarding.details.store_info', { n: step.detail });
    return step.detail;
}

const { loading: dismissing, track } = useVisitLoading();

function skip(): void {
    router.post('/onboarding/dismiss', {}, track());
}

function resume(): void {
    router.delete('/onboarding/dismiss', track());
}

const breadcrumbs = useSettingsBreadcrumbs(() => t('onboarding.title'));
</script>

<template>
    <Head :title="t('onboarding.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsShell :title="t('onboarding.title')" :description="t('onboarding.intro')" width="form">
            <section class="rounded-lg border border-line bg-card" :aria-label="t('onboarding.title')" data-onboarding-steps>
                <header class="space-y-2 border-b border-line px-4 pb-3 pt-3.5">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <h2 class="text-base font-bold">{{ t('onboarding.progress', { done: num(progress.done), total: num(progress.total) }) }}</h2>
                        <span class="text-13 tabular-nums text-fg-2">{{ formatNumber(locale, progress.percent / 100, { style: 'percent' }) }}</span>
                    </div>
                    <div
                        class="h-1.5 overflow-hidden rounded-full bg-sunken"
                        role="progressbar"
                        :aria-valuenow="progress.percent"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        :aria-label="t('onboarding.progress', { done: num(progress.done), total: num(progress.total) })"
                    >
                        <div
                            class="h-full rounded-full bg-brand transition-all motion-reduce:transition-none"
                            :style="{ width: `${progress.percent}%` }"
                        />
                    </div>
                    <p v-if="allDone" class="flex items-start gap-2 rounded-md bg-ok-soft px-3 py-2 text-13 text-ok" role="status">
                        <CircleCheck class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{ t('onboarding.all_done') }}
                    </p>
                    <p v-else-if="progress.complete" class="flex items-start gap-2 rounded-md bg-ok-soft px-3 py-2 text-13 text-ok" role="status">
                        <CircleCheck class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{ t('onboarding.required_done') }}
                    </p>
                </header>

                <ol>
                    <li
                        v-for="(step, i) in progress.steps"
                        :key="step.key"
                        class="flex flex-wrap items-center gap-x-3.5 gap-y-2 border-b border-line px-4 py-3.5 last:border-b-0"
                        :class="{ 'bg-brand-soft/40': next?.key === step.key }"
                        :data-step="step.key"
                        :data-done="step.done ? 'true' : 'false'"
                    >
                        <span
                            class="grid size-7 shrink-0 place-items-center rounded-full text-13 font-bold tabular-nums"
                            :class="step.done ? 'bg-ok-soft text-ok' : 'bg-sunken text-fg-2'"
                            aria-hidden="true"
                        >
                            <Check v-if="step.done" class="size-4" />
                            <template v-else>{{ num(i + 1) }}</template>
                        </span>

                        <div class="min-w-[min(100%,14rem)] flex-1 space-y-0.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-semibold text-foreground">{{ t(`onboarding.steps.${step.key}.title`) }}</h3>
                                <StatusChip v-if="step.required" :label="t('onboarding.required')" tone="brand" size="sm" />
                            </div>
                            <p class="text-13 text-fg-2">{{ t(`onboarding.steps.${step.key}.text`) }}</p>
                            <p v-if="detailOf(step)" class="text-13 font-medium text-foreground" dir="auto">{{ detailOf(step) }}</p>
                            <p v-if="step.blocked_by" class="text-13 text-warn">{{ t('onboarding.blocked_by_facebook') }}</p>
                        </div>

                        <div class="ms-auto flex shrink-0 items-center gap-2">
                            <StatusChip v-if="step.done" :label="t('onboarding.done')" tone="ok" :icon="Check" />
                            <StatusChip v-else-if="step.blocked_by" :label="t('onboarding.todo')" tone="neutral" />
                            <a
                                v-if="step.external && !step.done && !step.blocked_by"
                                :href="step.href"
                                :class="cn(buttonVariants({ variant: ctaVariant(step), size: 'sm' }))"
                            >
                                {{ t('onboarding.connect') }}
                            </a>
                            <Link
                                v-else-if="!step.blocked_by"
                                :href="step.href"
                                :class="cn(buttonVariants({ variant: ctaVariant(step), size: 'sm' }))"
                            >
                                {{ ctaLabel(step) }}
                            </Link>
                        </div>
                    </li>
                </ol>
            </section>

            <footer class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-13 text-fg-2">
                    <template v-if="!progress.dismissed">{{ t('onboarding.skip_hint') }}</template>
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="!progress.dismissed && !allDone" type="button" variant="ghost" size="sm" :loading="dismissing" @click="skip">{{
                        t('onboarding.skip')
                    }}</Button>
                    <Button
                        v-else-if="progress.dismissed && !allDone"
                        type="button"
                        variant="ghost"
                        size="sm"
                        :loading="dismissing"
                        @click="resume"
                        >{{ t('onboarding.resume') }}</Button
                    >
                    <Link href="/inbox" :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }))">{{ t('onboarding.go_inbox') }}</Link>
                </div>
            </footer>
        </SettingsShell>
    </AppLayout>
</template>
