<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import AppearanceTabs from '@/components/AppearanceTabs.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { useI18n } from '@/composables/useI18n';
import { type BreadcrumbItem } from '@/types';
import { computed } from 'vue';

import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';

const { t } = useI18n();

const breadcrumbItems = computed<BreadcrumbItem[]>(() => [{ title: t('nav.settings_layout.appearance'), href: '/settings/appearance' }]);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head :title="t('nav.settings_layout.appearance')" />

        <SettingsLayout>
            <div class="space-y-6 rounded-lg bg-card p-4 shadow-card">
                <HeadingSmall :title="t('nav.settings_layout.appearance_heading')" :description="t('nav.settings_layout.appearance_hint')" />
                <AppearanceTabs />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
