<script setup lang="ts">
/** App-level error page for 403 / 404 / 500 / 503 (bootstrap/app.php → respond). Inside the app shell when signed in. */
import { buttonVariants } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { formatNumber } from '@/i18n';
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CloudOff, FileQuestion, Lock, ServerCrash } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ status: 403 | 404 | 500 | 503 }>();

const { t, locale } = useI18n();
const page = usePage<SharedData>();
const signedIn = computed(() => Boolean(page.props.auth?.user));

const kind = computed(() => (([403, 404, 500, 503] as const).includes(props.status) ? props.status : 500));
const icon = computed(() => ({ 403: Lock, 404: FileQuestion, 500: ServerCrash, 503: CloudOff })[kind.value]);
const title = computed(() => t(`error_page.${kind.value}.title`));
const body = computed(() => t(`error_page.${kind.value}.body`));
const code = computed(() => formatNumber(locale.value, kind.value, { useGrouping: false }));

function goBack(): void {
    window.history.back();
}
</script>

<template>
    <Head :title="title" />

    <AppLayout v-if="signedIn">
        <section class="mx-auto flex w-full max-w-lg flex-1 flex-col items-center justify-center gap-3 px-4 py-16 text-center">
            <div class="flex size-14 items-center justify-center rounded-full bg-surface-accent text-primary">
                <component :is="icon" class="size-6" aria-hidden="true" />
            </div>
            <p class="text-xs font-medium text-muted-foreground tabular-nums">{{ t('error_page.code', { n: code }) }}</p>
            <h1 class="text-xl font-semibold text-balance text-foreground">{{ title }}</h1>
            <p class="max-w-sm text-sm text-pretty text-muted-foreground">{{ body }}</p>
            <div class="mt-3 flex flex-wrap items-center justify-center gap-2">
                <Link href="/inbox" :class="buttonVariants()">{{ t('error_page.home') }}</Link>
                <button type="button" :class="cn(buttonVariants({ variant: 'outline' }), 'gap-1.5')" @click="goBack">
                    <ArrowLeft class="size-4 rtl:-scale-x-100" aria-hidden="true" />
                    {{ t('error_page.back') }}
                </button>
            </div>
        </section>
    </AppLayout>

    <AuthLayout v-else :title="title" :description="body">
        <div class="flex flex-col items-center gap-2">
            <p class="text-xs font-medium text-muted-foreground tabular-nums">{{ t('error_page.code', { n: code }) }}</p>
            <Link :href="route('login')" :class="cn(buttonVariants(), 'w-full')">{{ t('error_page.login') }}</Link>
        </div>
    </AuthLayout>
</template>
