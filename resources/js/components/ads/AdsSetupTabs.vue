<script setup lang="ts">
/** «الإعداد» tabs (spec 4.1): الحسابات، المزامنة، الميديا باير، القواعد. */
import { useI18n } from '@/composables/useI18n';
import { Link, usePage } from '@inertiajs/vue3';

const { t } = useI18n();
const page = usePage();
const TABS = [
    { key: 'accounts', href: '/ads/accounts' },
    { key: 'sync', href: '/ads/sync' },
    { key: 'buyers', href: '/ads/setup/buyers' },
    { key: 'rules', href: '/ads/setup/rules' },
] as const;
const current = (href: string) => page.url.split('?')[0] === href;
</script>

<template>
    <nav class="scrollbar-none flex gap-1 overflow-x-auto border-b border-border" :aria-label="t('nav.ads_setup')">
        <Link
            v-for="tab in TABS"
            :key="tab.key"
            :href="tab.href"
            class="-mb-px inline-flex h-10 shrink-0 items-center whitespace-nowrap border-b-2 px-3 text-xs font-medium"
            :class="current(tab.href) ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
            :aria-current="current(tab.href) ? 'page' : undefined"
        >
            {{ t(`ads.control.setup.${tab.key}`) }}
        </Link>
    </nav>
</template>
