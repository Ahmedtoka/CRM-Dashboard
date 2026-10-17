<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/composables/useAppearance';
import { useI18n } from '@/composables/useI18n';
import type { User } from '@/types';
import { Link } from '@inertiajs/vue3';
import { Bell, Languages, LogOut, Monitor, Moon, Palette, Sun, UserRound } from 'lucide-vue-next';

/**
 * The avatar menu (IA §3.3 header, DESIGN §3 UserMenu): حسابي · المظهر · الإشعارات · اللغة · خروج, so a moderator never
 * needs the settings section for her own account.
 */
defineProps<{ user: User }>();

const { t, locale, setLocale } = useI18n();
const { appearance, updateAppearance } = useAppearance();
const themes = [
    { value: 'light', icon: Sun, label: 'nav.appearance_light' },
    { value: 'dark', icon: Moon, label: 'nav.appearance_dark' },
    { value: 'system', icon: Monitor, label: 'nav.appearance_system' },
] as const;
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
        <div v-if="user.role" class="px-1 pb-1.5">
            <span class="rounded-sm bg-sunken px-1.5 py-0.5 text-2xs font-medium text-fg-2">{{ t(`roles.${user.role}`) }}</span>
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="flex w-full items-center" href="/settings/profile" as="button">
                <UserRound class="me-2 size-4" aria-hidden="true" />
                {{ t('nav.settings_profile') }}
            </Link>
        </DropdownMenuItem>
        <!-- Appearance stays in the menu: one tap, no page (the segmented control keeps the menu open). -->
        <div class="flex items-center gap-2 px-2 py-1.5 text-sm" role="group" :aria-label="t('nav.appearance')">
            <Palette class="size-4 text-fg-2" aria-hidden="true" />
            <span class="flex-1">{{ t('nav.appearance') }}</span>
            <span class="inline-flex gap-0.5 rounded-md bg-sunken p-0.5">
                <button
                    v-for="theme in themes"
                    :key="theme.value"
                    type="button"
                    class="grid size-7 place-items-center rounded-[6px] text-fg-2 transition-colors hover:text-foreground"
                    :class="appearance === theme.value ? 'bg-card text-foreground shadow-e1' : ''"
                    :aria-pressed="appearance === theme.value"
                    :aria-label="t(theme.label)"
                    :title="t(theme.label)"
                    @click="updateAppearance(theme.value)"
                >
                    <component :is="theme.icon" class="size-4" aria-hidden="true" />
                </button>
            </span>
        </div>
        <DropdownMenuItem :as-child="true">
            <Link class="flex w-full items-center" href="/settings/notifications" as="button">
                <Bell class="me-2 size-4" aria-hidden="true" />
                {{ t('nav.settings_notifications') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem @select="setLocale(locale === 'ar' ? 'en' : 'ar')">
            <Languages class="me-2 size-4" aria-hidden="true" />
            <span>{{ t('nav.language') }}</span>
            <span class="ms-auto text-xs text-fg-2" :lang="locale === 'ar' ? 'en' : 'ar'">{{ t('nav.switch_language') }}</span>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link class="flex w-full items-center" method="post" :href="route('logout')" as="button">
            <LogOut class="me-2 size-4" aria-hidden="true" />
            {{ t('nav.logout') }}
        </Link>
    </DropdownMenuItem>
</template>
