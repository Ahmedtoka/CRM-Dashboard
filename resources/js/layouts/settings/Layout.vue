<script setup lang="ts">
import SettingsShell from '@/components/crm/settings/SettingsShell.vue';
import { useI18n } from '@/composables/useI18n';
import { sectionTabs } from '@/lib/sectionTabs';
import { computed } from 'vue';

/**
 * «حسابي» (D5): one settings page with horizontal tabs (البيانات · كلمة السر · المظهر · الإشعارات) inside the shared
 * settings frame (side list on a wide screen, header, tabs). Each tab page passes useSettingsBreadcrumbs to AppLayout.
 */
const { t } = useI18n();
const tabs = computed(() => sectionTabs.account(t));
</script>

<template>
    <SettingsShell
        :title="t('settings_hub.items.profile')"
        :description="t('nav.settings_layout.description')"
        :tabs="tabs"
        :tabs-label="t('section_tabs.account.label')"
        width="form"
    >
        <slot />
    </SettingsShell>
</template>
