<script setup lang="ts">
import PageHeader from '@/components/crm/PageHeader.vue';
import SettingsHubCard from '@/components/crm/SettingsHubCard.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { hubItemLabel, type HubGroup } from '@/lib/settingsHub';
import { Head, Link } from '@inertiajs/vue3';
import { LayoutGrid, Link2, Megaphone, Rocket, Store, UserRound, Users, Wrench, type LucideIcon } from 'lucide-vue-next';

/**
 * «الإعدادات» (A5, IA §3.3 / DESIGN §4 Hub): one link in the menu opens grouped cards, each item with its state; on a
 * wide screen the groups are also a sticky list on the side. Groups and items come filtered by role from the server.
 */
defineProps<{ groups: HubGroup[] }>();

const { t } = useI18n();

const LOOK: Record<string, { icon: LucideIcon; tone: 'brand' | 'info' | 'warn' | 'neutral' }> = {
    account: { icon: UserRound, tone: 'brand' },
    team: { icon: Users, tone: 'info' },
    channels: { icon: Link2, tone: 'info' },
    store: { icon: Store, tone: 'warn' },
    ads: { icon: Megaphone, tone: 'brand' },
    start: { icon: Rocket, tone: 'warn' },
    dev: { icon: Wrench, tone: 'neutral' },
};
const look = (id: string) => LOOK[id] ?? { icon: LayoutGrid, tone: 'neutral' as const };
</script>

<template>
    <Head :title="t('settings_hub.title')" />

    <AppLayout :breadcrumbs="[{ title: t('settings_hub.title'), href: '/settings' }]">
        <div class="mx-auto w-full max-w-[1440px] space-y-5 p-4 md:p-6">
            <PageHeader :title="t('settings_hub.title')" :description="t('settings_hub.description')" />

            <div class="grid items-start gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
                <nav class="sticky top-[4.5rem] hidden flex-col gap-3.5 lg:flex" :aria-label="t('settings_hub.groups_label')">
                    <span class="flex items-center gap-2 rounded-md bg-card px-2.5 py-[7px] text-[13.5px] font-semibold text-brand-ink shadow-e1">
                        <LayoutGrid class="size-4" aria-hidden="true" />{{ t('settings_hub.all') }}
                    </span>
                    <div v-for="group in groups" :key="group.id">
                        <p class="mb-1 px-2.5 text-xs font-semibold text-fg-3">{{ t(`settings_hub.groups.${group.id}.title`) }}</p>
                        <Link
                            v-for="item in group.items"
                            :key="item.id"
                            :href="item.href"
                            class="flex w-full items-center rounded-md px-2.5 py-[7px] text-[13.5px] font-medium text-fg-2 transition-colors hover:bg-card hover:text-foreground"
                        >
                            {{ t(hubItemLabel(item)) }}
                        </Link>
                    </div>
                </nav>

                <div class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,300px),1fr))] gap-3.5">
                    <SettingsHubCard v-for="group in groups" :key="group.id" :group="group" :icon="look(group.id).icon" :tone="look(group.id).tone" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
