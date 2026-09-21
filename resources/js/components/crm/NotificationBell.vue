<script setup lang="ts">
import { DropdownMenu, DropdownMenuContent, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useI18n } from '@/composables/useI18n';
import { useNotifications } from '@/composables/useNotifications';
import { formatCount } from '@/lib/format';
import type { AppNotification } from '@/types/crm';
import { Link, router } from '@inertiajs/vue3';
import { Bell } from 'lucide-vue-next';
import { computed } from 'vue';

const { t, locale } = useI18n();
const notifications = useNotifications();

function reasonLabel(n: AppNotification): string {
    const reason = String(n.data.reason ?? '');

    return reason ? t(`reports.reasons.${reason}`) : '';
}

function itemText(n: AppNotification): string {
    if (n.type === 'conversation.handover' || n.type === 'conversation.handover_urgent') {
        const name = String(n.data.customer_name ?? t('notifications.customer'));
        const urgent = n.type === 'conversation.handover_urgent' ? `${t('notifications.types.handover_urgent')} · ` : '';

        return `${urgent}${t('notifications.handover_item', { name })} · ${reasonLabel(n)}`;
    }

    if (n.type === 'channel.problem') {
        const title = t('notifications.channel_problem_item', { name: String(n.data.name ?? '') });
        // The health check writes the notification from the scheduler, so its `excerpt` is
        // frozen in the server's default language; the `codes` beside it are not.
        const codes = Array.isArray(n.data.codes) ? (n.data.codes as string[]) : [];
        const detail = codes.length ? codes.map((code) => t(`notifications.channel_problem_codes.${code}`)).join(' · ') : String(n.data.excerpt ?? '');

        return detail ? `${title}: ${detail}` : title;
    }

    const excerpt = String(n.data.excerpt ?? '');

    return excerpt ? `${t('notifications.mention_item', { name: String(n.data.by ?? '') })}: ${excerpt}` : t('notifications.mention_item', { name: String(n.data.by ?? '') });
}

function open(n: AppNotification): void {
    void notifications.markRead([n.id]);
    if (n.type === 'channel.problem') {
        router.visit('/settings/integrations');
        return;
    }
    const conversationId = n.data.conversation_id;
    if (conversationId) router.visit(`/inbox?c=${conversationId}`);
}

const desktopEnabled = computed(() => notifications.permission.value === 'granted' && notifications.prefs.value.desktop_notifications);
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger class="relative flex size-9 items-center justify-center rounded-full text-muted-foreground hover:bg-muted" :aria-label="t('notifications.title')">
            <Bell class="size-5" aria-hidden="true" />
            <span
                v-if="notifications.unreadNotifications.value > 0"
                class="absolute end-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-2xs font-semibold text-white"
            >
                {{ formatCount(notifications.unreadNotifications.value, locale) }}
            </span>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80">
            <div class="flex items-center justify-between px-2 py-1.5">
                <DropdownMenuLabel class="p-0 text-sm">{{ t('notifications.title') }}</DropdownMenuLabel>
                <button
                    type="button"
                    class="text-2xs text-primary hover:underline disabled:pointer-events-none disabled:opacity-50"
                    :disabled="notifications.unreadNotifications.value === 0"
                    @click="notifications.markRead()"
                >
                    {{ t('notifications.mark_all_read') }}
                </button>
            </div>
            <DropdownMenuSeparator />
            <div class="max-h-80 overflow-y-auto">
                <p v-if="!notifications.items.value.length" class="px-2 py-4 text-center text-xs text-muted-foreground">{{ t('notifications.empty') }}</p>
                <button
                    v-for="n in notifications.items.value"
                    :key="n.id"
                    type="button"
                    class="block w-full px-2 py-2 text-start text-xs hover:bg-muted"
                    :class="n.read_at ? 'text-muted-foreground' : 'font-medium text-foreground'"
                    @click="open(n)"
                >
                    {{ itemText(n) }}
                </button>
            </div>
            <DropdownMenuSeparator />
            <div class="space-y-1.5 px-2 py-1.5 text-xs">
                <label class="flex items-center justify-between gap-2">
                    {{ t('notifications.sound') }}
                    <input
                        type="checkbox"
                        class="rounded border-input"
                        :checked="notifications.prefs.value.sound"
                        @change="notifications.savePrefs({ sound: ($event.target as HTMLInputElement).checked })"
                    />
                </label>
                <label v-if="notifications.permission.value === 'granted'" class="flex items-center justify-between gap-2">
                    {{ t('notifications.desktop') }}
                    <input
                        type="checkbox"
                        class="rounded border-input"
                        :checked="desktopEnabled"
                        @change="notifications.savePrefs({ desktop_notifications: ($event.target as HTMLInputElement).checked })"
                    />
                </label>
                <button
                    v-else-if="notifications.permission.value === 'default'"
                    type="button"
                    class="w-full rounded-md bg-muted px-2 py-1 text-start text-2xs hover:bg-surface-accent"
                    @click="notifications.requestDesktopPermission()"
                >
                    {{ t('notifications.enable_desktop') }}
                </button>
                <p v-else-if="notifications.permission.value === 'denied'" class="text-2xs text-muted-foreground">{{ t('notifications.desktop_denied') }}</p>
            </div>
            <DropdownMenuSeparator />
            <Link href="/settings/notifications" class="block px-2 py-1.5 text-xs text-primary hover:underline">{{ t('notifications.title') }}</Link>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
