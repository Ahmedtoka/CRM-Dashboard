<script setup lang="ts">
import CommandPalette from '@/components/crm/CommandPalette.vue';
import NotificationBell from '@/components/crm/NotificationBell.vue';
import ShortcutsDialog from '@/components/crm/ShortcutsDialog.vue';
import ToastStack from '@/components/crm/ToastStack.vue';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { useI18n } from '@/composables/useI18n';
import { useNotifications } from '@/composables/useNotifications';
import { formatKeys, useShortcuts } from '@/composables/useShortcuts';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType, SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { Search, TriangleAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
    /** Full-height pages (inbox): the content area is exactly one viewport tall and the page fills what's left with flex. */
    fill?: boolean;
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    fill: false,
});

const page = usePage<SharedData>();
const { t } = useI18n();

const alerts = computed(() => page.props.channelAlerts ?? []);

// `shift+?` opens the cheat-sheet from anywhere; the escape entry is
// documentation only — Radix dialogs/sheets/menus already close on Esc.
const shortcutsOpen = ref(false);
const palette = useCommandPalette();
const notifications = useNotifications();
notifications.start();
useShortcuts([
    { id: 'global.help', keys: ['shift+?'], labelKey: 'shortcuts.help', group: 'global', handler: () => (shortcutsOpen.value = true) },
    { id: 'global.escape', keys: ['escape'], labelKey: 'shortcuts.close', group: 'global', allowInInput: true },
    { id: 'global.search', keys: ['mod+k'], labelKey: 'shortcuts.search', group: 'global', allowInInput: true, handler: () => palette.show() },
]);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs" :content-class="fill ? 'h-svh max-h-svh overflow-hidden' : undefined">
        <template #topbar-search>
            <button
                type="button"
                class="flex h-9 w-full max-w-md items-center gap-2 rounded-full bg-elevated px-3 text-sm text-muted-foreground hover:bg-muted"
                @click="palette.show()"
            >
                <Search class="size-4" aria-hidden="true" />
                {{ t('search.placeholder') }}
                <kbd class="ms-auto text-2xs" dir="ltr">{{ formatKeys('mod+k') }}</kbd>
            </button>
        </template>
        <template #topbar-actions>
            <NotificationBell />
        </template>

        <div v-if="alerts.length" role="alert" class="border-b border-amber-200 bg-amber-50 px-4 py-1.5 text-xs text-amber-900">
            <p v-for="alert in alerts" :key="alert.id" class="flex items-center gap-2">
                <TriangleAlert class="size-3.5 shrink-0" />
                {{ t('alerts.channel_error', { name: alert.name, error: alert.last_error ?? '' }) }}
            </p>
        </div>
        <slot />
        <ToastStack />
        <ShortcutsDialog v-model:open="shortcutsOpen" />
        <CommandPalette />
    </AppLayout>
</template>
