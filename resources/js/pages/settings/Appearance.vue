<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import AppearanceTabs from '@/components/AppearanceTabs.vue';
import SettingsPanel from '@/components/crm/settings/SettingsPanel.vue';
import { useI18n } from '@/composables/useI18n';
import { useSettingsBreadcrumbs } from '@/composables/useSettingsBreadcrumbs';

import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';

const { t } = useI18n();

const breadcrumbItems = useSettingsBreadcrumbs(() => t('section_tabs.account.appearance'));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head :title="t('nav.settings_layout.appearance')" />

        <SettingsLayout>
            <SettingsPanel :title="t('nav.settings_layout.appearance_heading')" :description="t('nav.settings_layout.appearance_hint')">
                <AppearanceTabs class="max-w-xl py-3" />
            </SettingsPanel>
        </SettingsLayout>
    </AppLayout>
</template>
