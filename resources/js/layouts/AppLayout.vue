<script setup lang="ts">
import CommandPalette from '@/components/crm/CommandPalette.vue';
import NotificationBell from '@/components/crm/NotificationBell.vue';
import ShortcutsDialog from '@/components/crm/ShortcutsDialog.vue';
import ToastStack from '@/components/crm/ToastStack.vue';
import TopProgress from '@/components/crm/TopProgress.vue';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { useHeartbeat } from '@/composables/useHeartbeat';
import { useI18n } from '@/composables/useI18n';
import { useNotifications } from '@/composables/useNotifications';
import { formatKeys, useShortcuts } from '@/composables/useShortcuts';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType, SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Search, TriangleAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
    /** Full-height pages (inbox): the content area is exactly one viewport tall and the page fills what's left with flex. */
    fill?: boolean;
    /** Inbox and board: the sidebar starts collapsed to its icon rail. */
    workspace?: boolean;
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    fill: false,
    workspace: false,
});

const page = usePage<SharedData>();
const { t } = useI18n();

const alerts = computed(() => page.props.channelAlerts ?? []);
const isAdmin = computed(() => page.props.auth.user?.role === 'admin');

// `shift+?` opens the cheat-sheet from anywhere; the escape entry is
// documentation only — Radix dialogs/sheets/menus already close on Esc.
const shortcutsOpen = ref(false);
const palette = useCommandPalette();
const notifications = useNotifications();
notifications.start();
// Presence and search are for inbox staff; media buyers and content users are kept to /ads (RestrictAdsRoles answers 403).
const adsOnly = ['media_buyer', 'content'].includes(page.props.auth.user?.role ?? '');
if (!adsOnly) useHeartbeat().start();
useShortcuts([
    { id: 'global.help', keys: ['shift+?'], labelKey: 'shortcuts.help', group: 'global', handler: () => (shortcutsOpen.value = true) },
    { id: 'global.escape', keys: ['escape'], labelKey: 'shortcuts.close', group: 'global', allowInInput: true },
    { id: 'global.search', keys: ['mod+k'], labelKey: 'shortcuts.search', group: 'global', allowInInput: true, handler: () => !adsOnly && palette.show() },
]);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs" :content-class="fill ? 'h-svh max-h-svh overflow-hidden' : undefined" :workspace="workspace">
        <TopProgress />
        <template v-if="!adsOnly" #topbar-search>
            <button
                type="button"
                class="flex size-9 shrink-0 items-center justify-center gap-2 rounded-full bg-elevated px-0 text-sm text-muted-foreground hover:bg-muted sm:h-9 sm:w-full sm:max-w-md sm:justify-start sm:px-3"
                :aria-label="t('search.placeholder')"
                @click="palette.show()"
            >
                <Search class="size-4 shrink-0" aria-hidden="true" />
                <span class="hidden truncate sm:inline">{{ t('search.placeholder') }}</span>
                <kbd class="ms-auto hidden text-2xs sm:inline" dir="ltr">{{ formatKeys('mod+k') }}</kbd>
            </button>
        </template>
        <template #topbar-actions>
            <NotificationBell />
        </template>

        <div v-if="alerts.length" role="alert" class="border-b border-amber-200 bg-amber-50 px-4 py-1.5 text-xs text-amber-900">
            <p v-for="alert in alerts" :key="alert.id" class="flex items-center gap-2">
                <TriangleAlert class="size-3.5 shrink-0" />
                <span class="min-w-0 flex-1">{{ t('alerts.channel_error', { name: alert.name, error: alert.last_error ?? '' }) }}</span>
                <Link v-if="isAdmin" :href="alert.platform === 'shopify' ? '/settings/shopify' : '/settings/integrations'" class="shrink-0 font-semibold underline underline-offset-2">
                    {{ t('settings.integrations.title') }}
                </Link>
            </p>
        </div>
        <slot />
        <ToastStack />
        <ShortcutsDialog v-model:open="shortcutsOpen" />
        <CommandPalette v-if="!adsOnly" />
    </AppLayout>
</template>
