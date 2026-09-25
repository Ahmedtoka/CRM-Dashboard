<script setup lang="ts">
/** «ابدأ من هنا» (owner, 2026-09-26): the admin's connect-everything checklist, read from what is really connected. */
import StatusChip from '@/components/crm/StatusChip.vue';
import { buttonVariants } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { cn } from '@/lib/utils';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Bot, CheckCircle2, Circle, Facebook, Instagram, Link2, MessageCircle, ShoppingBag, Store } from 'lucide-vue-next';
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

const { t } = useI18n();
const page = usePage();
const appName = computed(() => (page.props.name as string) || 'CRM');

const icons = { facebook: Facebook, instagram: Instagram, whatsapp: MessageCircle, shopify: ShoppingBag, store_info: Store, bot: Bot, test_link: Link2 } as const;
const colors = { facebook: '#0866FF', instagram: '#E1306C', whatsapp: '#25D366', shopify: '#96BF48', store_info: '#7C3AED', bot: '#0EA5E9', test_link: '#F59E0B' } as const;

const next = computed(() => props.progress.steps.find((s) => !s.done && !s.blocked_by) ?? null);

function detailOf(step: Step): string | null {
    if (!step.detail) return null;
    if (step.key === 'store_info') return t('onboarding.details.store_info', { n: step.detail });
    return step.detail;
}

function skip(): void {
    router.post('/onboarding/dismiss');
}

function resume(): void {
    router.delete('/onboarding/dismiss');
}

const breadcrumbs = computed(() => [{ title: t('onboarding.title'), href: '/onboarding' }]);
</script>

<template>
    <Head :title="t('onboarding.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-3xl space-y-5 p-3 md:p-6">
            <header class="space-y-3 rounded-xl border border-border bg-card p-5 shadow-card">
                <h1 class="text-xl font-semibold text-foreground">{{ t('onboarding.welcome', { name: appName }) }}</h1>
                <p class="text-sm text-muted-foreground">{{ t('onboarding.intro') }}</p>
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between text-xs text-muted-foreground">
                        <span>{{ t('onboarding.progress', { done: progress.done, total: progress.total }) }}</span>
                        <span class="tabular-nums">{{ progress.percent }}%</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="progress.percent" aria-valuemin="0" aria-valuemax="100">
                        <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${progress.percent}%` }" />
                    </div>
                </div>
                <p v-if="progress.complete" class="rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-800 dark:text-emerald-200">{{ t('onboarding.all_done') }}</p>
            </header>

            <ol class="space-y-3">
                <li
                    v-for="(step, i) in progress.steps"
                    :key="step.key"
                    class="flex gap-3 rounded-xl border bg-card p-4 shadow-card"
                    :class="[step.done ? 'border-emerald-500/30' : next?.key === step.key ? 'border-primary/50 ring-1 ring-primary/20' : 'border-border', { 'opacity-70': step.blocked_by }]"
                >
                    <div class="flex shrink-0 flex-col items-center gap-2">
                        <span class="flex size-10 items-center justify-center rounded-full text-white" :style="{ background: colors[step.key] }" aria-hidden="true">
                            <component :is="icons[step.key]" class="size-5" />
                        </span>
                        <CheckCircle2 v-if="step.done" class="size-5 text-emerald-600" aria-hidden="true" />
                        <Circle v-else class="size-5 text-muted-foreground/50" aria-hidden="true" />
                    </div>

                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-2xs tabular-nums text-muted-foreground">{{ i + 1 }}</span>
                            <h2 class="text-sm font-semibold text-foreground">{{ t(`onboarding.steps.${step.key}.title`) }}</h2>
                            <StatusChip :label="step.done ? t('onboarding.done') : t('onboarding.todo')" :tone="step.done ? 'positive' : 'neutral'" />
                            <StatusChip v-if="step.required" :label="t('onboarding.required')" tone="info" />
                        </div>
                        <p class="text-xs text-muted-foreground">{{ t(`onboarding.steps.${step.key}.text`) }}</p>
                        <p v-if="detailOf(step)" class="text-xs font-medium text-foreground" dir="auto">{{ detailOf(step) }}</p>
                        <p v-if="step.blocked_by" class="text-xs text-amber-700 dark:text-amber-300">{{ t('onboarding.blocked_by_facebook') }}</p>

                        <div class="pt-1">
                            <a v-if="step.external && !step.done && !step.blocked_by" :href="step.href" :class="cn(buttonVariants({ size: 'sm' }))">
                                {{ t('onboarding.connect') }}
                            </a>
                            <Link v-else-if="!step.blocked_by" :href="step.href" :class="cn(buttonVariants({ variant: step.done ? 'outline' : 'default', size: 'sm' }))">
                                {{ step.done ? t('onboarding.open') : t('onboarding.connect') }}
                            </Link>
                        </div>
                    </div>
                </li>
            </ol>

            <footer class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-4 shadow-card">
                <div class="text-xs text-muted-foreground">
                    <template v-if="!progress.dismissed">{{ t('onboarding.skip_hint') }}</template>
                </div>
                <div class="flex gap-2">
                    <button v-if="!progress.dismissed && !progress.complete" type="button" :class="cn(buttonVariants({ variant: 'ghost', size: 'sm' }))" @click="skip">{{ t('onboarding.skip') }}</button>
                    <button v-else-if="progress.dismissed && !progress.complete" type="button" :class="cn(buttonVariants({ variant: 'ghost', size: 'sm' }))" @click="resume">{{ t('onboarding.resume') }}</button>
                    <Link href="/inbox" :class="cn(buttonVariants({ size: 'sm' }))">{{ t('onboarding.go_inbox') }}</Link>
                </div>
            </footer>
        </div>
    </AppLayout>
</template>
