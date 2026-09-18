<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { useI18n } from '@/composables/useI18n';
import { useNotifications } from '@/composables/useNotifications';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { NotificationPreferences } from '@/types';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ preferences: NotificationPreferences }>();

const { t } = useI18n();
const notifications = useNotifications();
notifications.prefs.value = { ...notifications.prefs.value, ...props.preferences };

const breadcrumbs = [{ title: t('notifications.title'), href: '/settings/notifications' }];

function testSound(): void {
    const audio = new Audio('/sounds/notify.wav');
    audio.volume = notifications.prefs.value.sound_volume;
    void audio.play().catch(() => undefined);
}

const permissionText = computed(() => {
    if (notifications.permission.value === 'unsupported') return null;
    if (notifications.permission.value === 'granted') return null;
    if (notifications.permission.value === 'denied') return t('notifications.desktop_denied');

    return null;
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('notifications.title')" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall :title="t('notifications.title')" />

                <div class="space-y-6 rounded-lg bg-card p-4 shadow-card">
                    <label class="flex items-center justify-between gap-2 text-sm">
                        <span class="font-medium">{{ t('notifications.sound') }}</span>
                        <input
                            type="checkbox"
                            class="size-4 rounded border-input"
                            :checked="notifications.prefs.value.sound"
                            @change="notifications.savePrefs({ sound: ($event.target as HTMLInputElement).checked })"
                        />
                    </label>

                    <div class="grid gap-2">
                        <label class="flex items-center justify-between gap-2 text-sm" for="notifications-volume">
                            <span class="font-medium">{{ t('notifications.volume') }}</span>
                        </label>
                        <div class="flex items-center gap-3">
                            <input
                                id="notifications-volume"
                                type="range"
                                min="0"
                                max="1"
                                step="0.1"
                                class="w-full"
                                :value="notifications.prefs.value.sound_volume"
                                @change="notifications.savePrefs({ sound_volume: Number(($event.target as HTMLInputElement).value) })"
                            />
                            <button type="button" class="shrink-0 rounded-md border border-input px-3 py-1.5 text-xs hover:bg-muted" @click="testSound">
                                {{ t('notifications.test_sound') }}
                            </button>
                        </div>
                    </div>

                    <div class="grid gap-2 border-t border-border pt-4">
                        <label class="flex items-center justify-between gap-2 text-sm">
                            <span class="font-medium">{{ t('notifications.desktop') }}</span>
                            <input
                                v-if="notifications.permission.value === 'granted'"
                                type="checkbox"
                                class="size-4 rounded border-input"
                                :checked="notifications.prefs.value.desktop_notifications"
                                @change="notifications.savePrefs({ desktop_notifications: ($event.target as HTMLInputElement).checked })"
                            />
                        </label>
                        <button
                            v-if="notifications.permission.value === 'default'"
                            type="button"
                            class="w-fit rounded-md border border-input px-3 py-1.5 text-xs hover:bg-muted"
                            @click="notifications.requestDesktopPermission()"
                        >
                            {{ t('notifications.enable_desktop') }}
                        </button>
                        <p v-if="permissionText" class="text-xs text-muted-foreground">{{ permissionText }}</p>
                    </div>

                    <fieldset class="grid gap-2 border-t border-border pt-4">
                        <legend class="mb-1 text-sm font-medium">{{ t('notifications.scope') }}</legend>
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                type="radio"
                                name="notify_scope"
                                class="size-4"
                                value="all_visible"
                                :checked="notifications.prefs.value.notify_scope === 'all_visible'"
                                @change="notifications.savePrefs({ notify_scope: 'all_visible' })"
                            />
                            {{ t('notifications.scope_all_visible') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                type="radio"
                                name="notify_scope"
                                class="size-4"
                                value="mine_and_handover"
                                :checked="notifications.prefs.value.notify_scope === 'mine_and_handover'"
                                @change="notifications.savePrefs({ notify_scope: 'mine_and_handover' })"
                            />
                            {{ t('notifications.scope_mine_and_handover') }}
                        </label>
                    </fieldset>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
