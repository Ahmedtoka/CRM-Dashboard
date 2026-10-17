<script setup lang="ts">
import PageHeader from '@/components/crm/PageHeader.vue';
import SectionTabs from '@/components/crm/SectionTabs.vue';
import { useI18n } from '@/composables/useI18n';
import { sectionTabs } from '@/lib/sectionTabs';
import { computed } from 'vue';

/** «حسابي» (D5): one page with horizontal tabs (البيانات · كلمة السر · المظهر · الإشعارات); the inner side menu is gone. */
const { t } = useI18n();
const tabs = computed(() => sectionTabs.account(t));
</script>

<template>
    <div class="mx-auto w-full max-w-5xl space-y-5 p-4 md:p-6">
        <PageHeader
            :title="t('nav.settings_profile')"
            :description="t('nav.settings_layout.description')"
            :breadcrumbs="[{ label: t('settings_hub.title'), href: '/settings' }, { label: t('nav.settings_profile') }]"
        />
        <SectionTabs :tabs="tabs" :label="t('section_tabs.account.label')" />
        <section class="min-w-0 max-w-3xl space-y-12">
            <slot />
        </section>
    </div>
</template>
