<script setup lang="ts">
/** Ads Hub materials library: placeholder until the real UI (Task 13). */
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

const { t } = useI18n();
const breadcrumbs = [{ title: t('nav.ads'), href: '/ads' }];
</script>

<template>
    <Head :title="t('nav.ads_materials')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl p-3 md:p-6">
            <h1 class="text-xl font-semibold">{{ t('nav.ads_materials') }}</h1>
        </div>
    </AppLayout>
</template>
