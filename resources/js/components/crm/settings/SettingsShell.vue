<script setup lang="ts">
import PageHeader from '@/components/crm/PageHeader.vue';
import SectionTabs from '@/components/crm/SectionTabs.vue';
import SettingsHubNav from '@/components/crm/settings/SettingsHubNav.vue';
import { useI18n } from '@/composables/useI18n';
import type { SectionTab } from '@/lib/sectionTabs';
import { hubLocate, hubNav, settingsCrumbs } from '@/lib/settingsHub';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Every settings page (DESIGN §4 template 4 «Hub + details»): the groups as a sticky side list on a wide screen, then
 * H1 + description + an optional tab strip + the page's own rows. «الإعدادات › المجموعة» rides in the top bar (pass
 * useSettingsBreadcrumbs to AppLayout) and above the title on a phone. On a phone the side list hides and the hub
 * cards are the way in. The page keeps its AppLayout; this is only the frame inside it.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        tabs?: SectionTab[];
        tabsLabel?: string;
        /** Kept for callers: every settings page now shares one column width (960px) so the header never jumps between tabs; a form narrows its own panels. */
        width?: 'form' | 'wide';
        freshness?: string | null;
    }>(),
    { description: undefined, tabs: () => [], tabsLabel: '', width: 'wide', freshness: null },
);

const { t } = useI18n();
const page = usePage<SharedData>();
const groups = computed(() => hubNav(page.props.auth.user?.role as never, Boolean((page.props as { devTools?: boolean }).devTools)));
const here = computed(() => hubLocate(groups.value, page.url));
const crumbs = computed(() => settingsCrumbs(t, here.value?.group.id ?? null, props.title).map((c) => ({ label: c.title, href: c.href })));
</script>

<template>
    <div class="mx-auto w-full max-w-[1440px] p-4 md:p-6">
        <div class="grid items-start gap-6 lg:grid-cols-[232px_minmax(0,1fr)]">
            <SettingsHubNav :groups="groups" :current="here?.item.id ?? null" class="sticky top-[4.5rem] hidden lg:flex" />

            <div class="min-w-0 max-w-[960px] space-y-5" :data-width="width" data-settings-shell>
                <PageHeader :title="title" :description="description" :breadcrumbs="crumbs" :freshness="freshness">
                    <template v-if="$slots.extra" #extra><slot name="extra" /></template>
                    <slot name="actions" />
                </PageHeader>
                <SectionTabs v-if="tabs.length" :tabs="tabs" :label="tabsLabel || title" />
                <slot />
            </div>
        </div>
    </div>
</template>
