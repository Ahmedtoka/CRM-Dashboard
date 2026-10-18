<script setup lang="ts">
import PageHeader from '@/components/crm/PageHeader.vue';
import SettingsHubCard from '@/components/crm/SettingsHubCard.vue';
import SettingsHubNav from '@/components/crm/settings/SettingsHubNav.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { HubGroup } from '@/lib/settingsHub';
import type { SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { LayoutGrid, Link2, Megaphone, Rocket, Store, UserRound, Users, Wrench, type LucideIcon } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * «الإعدادات» (A5, IA §3.3 / DESIGN §4 Hub): one link in the menu opens grouped cards, each item with its state; on a
 * wide screen the groups are also a sticky list on the side (the same list every settings page carries). Groups and
 * items come filtered by role from the server.
 */
defineProps<{ groups: HubGroup[] }>();

const { t } = useI18n();
const page = usePage<SharedData>();
// The admin sees everything and the dots that need a look; everyone else sees only what is theirs to change.
const description = computed(() => t(page.props.auth.user?.role === 'admin' ? 'settings_hub.description' : 'settings_hub.description_limited'));

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
        <div class="mx-auto w-full max-w-[1440px] p-4 md:p-6">
            <div class="grid items-start gap-6 lg:grid-cols-[232px_minmax(0,1fr)]">
                <SettingsHubNav :groups="groups" :current="null" class="sticky top-[4.5rem] hidden lg:flex" />

                <div class="min-w-0 space-y-5">
                    <PageHeader :title="t('settings_hub.title')" :description="description" />
                    <div class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,300px),1fr))] gap-3.5">
                        <SettingsHubCard
                            v-for="group in groups"
                            :key="group.id"
                            :group="group"
                            :icon="look(group.id).icon"
                            :tone="look(group.id).tone"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
