<script setup lang="ts">
import ToggleSwitch from '@/components/crm/ToggleSwitch.vue';
import SettingsPanel from '@/components/crm/settings/SettingsPanel.vue';
import SettingsRow from '@/components/crm/settings/SettingsRow.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useNotifications } from '@/composables/useNotifications';
import { useSettingsBreadcrumbs } from '@/composables/useSettingsBreadcrumbs';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { NotificationPreferences } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Volume2 } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ preferences: NotificationPreferences }>();

const { t } = useI18n();
const notifications = useNotifications();
notifications.prefs.value = { ...notifications.prefs.value, ...props.preferences };

const breadcrumbs = useSettingsBreadcrumbs(() => t('section_tabs.account.notifications'));

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

const scopes = ['all_visible', 'mine_and_handover'] as const;
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('notifications.title')" />

        <SettingsLayout>
            <SettingsPanel :title="t('notifications.title')">
                <SettingsRow :title="t('notifications.sound')" :description="t('notifications.sound_hint')" for="notifications-sound">
                    <ToggleSwitch
                        id="notifications-sound"
                        :model-value="notifications.prefs.value.sound"
                        :label="t('notifications.sound')"
                        @update:model-value="notifications.savePrefs({ sound: $event })"
                    />
                </SettingsRow>

                <SettingsRow :title="t('notifications.volume')" for="notifications-volume" stack>
                    <div class="flex w-full items-center gap-3">
                        <input
                            id="notifications-volume"
                            type="range"
                            min="0"
                            max="1"
                            step="0.1"
                            class="min-w-0 flex-1 accent-brand"
                            :value="notifications.prefs.value.sound_volume"
                            @change="notifications.savePrefs({ sound_volume: Number(($event.target as HTMLInputElement).value) })"
                        />
                        <Button type="button" variant="outline" size="sm" class="shrink-0" @click="testSound">
                            <Volume2 class="size-4" aria-hidden="true" />{{ t('notifications.test_sound') }}
                        </Button>
                    </div>
                </SettingsRow>

                <SettingsRow
                    :title="t('notifications.desktop')"
                    :description="t('notifications.desktop_hint')"
                    :for="notifications.permission.value === 'granted' ? 'notifications-desktop' : undefined"
                >
                    <template v-if="permissionText" #below>
                        <p class="mt-1 text-xs text-warn">{{ permissionText }}</p>
                    </template>
                    <ToggleSwitch
                        v-if="notifications.permission.value === 'granted'"
                        id="notifications-desktop"
                        :model-value="notifications.prefs.value.desktop_notifications"
                        :label="t('notifications.desktop')"
                        @update:model-value="notifications.savePrefs({ desktop_notifications: $event })"
                    />
                    <Button
                        v-else-if="notifications.permission.value === 'default'"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="notifications.requestDesktopPermission()"
                    >
                        {{ t('notifications.enable_desktop') }}
                    </Button>
                </SettingsRow>

                <SettingsRow :title="t('notifications.scope')" stack>
                    <fieldset class="grid w-full gap-1">
                        <legend class="sr-only">{{ t('notifications.scope') }}</legend>
                        <label
                            v-for="scope in scopes"
                            :key="scope"
                            class="flex min-h-10 cursor-pointer items-center gap-2.5 rounded-md px-1 text-sm hover:bg-surface-2"
                        >
                            <input
                                type="radio"
                                name="notify_scope"
                                class="size-4 accent-brand"
                                :value="scope"
                                :checked="notifications.prefs.value.notify_scope === scope"
                                @change="notifications.savePrefs({ notify_scope: scope })"
                            />
                            {{ t(`notifications.scope_${scope}`) }}
                        </label>
                    </fieldset>
                </SettingsRow>
            </SettingsPanel>
        </SettingsLayout>
    </AppLayout>
</template>
