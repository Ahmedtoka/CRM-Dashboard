<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { useI18n } from '@/composables/useI18n';
import type { User } from '@/types';
import { Link } from '@inertiajs/vue3';
import { Languages, LogOut, Settings } from 'lucide-vue-next';

interface Props {
    user: User;
}

defineProps<Props>();

const { t, locale, setLocale } = useI18n();
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
        <div v-if="user.role" class="px-1 pb-1.5">
            <span class="rounded bg-muted px-1.5 py-0.5 text-2xs font-medium text-muted-foreground">{{ t(`roles.${user.role}`) }}</span>
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full" href="/settings/profile" as="button">
                <Settings class="me-2 h-4 w-4" />
                {{ t('nav.settings_profile') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem @select="setLocale(locale === 'ar' ? 'en' : 'ar')">
            <Languages class="me-2 h-4 w-4" />
            <span>{{ t('nav.language') }}</span>
            <span class="ms-auto text-xs text-muted-foreground" :lang="locale === 'ar' ? 'en' : 'ar'">{{ t('nav.switch_language') }}</span>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link class="block w-full" method="post" :href="route('logout')" as="button">
            <LogOut class="me-2 h-4 w-4" />
            {{ t('nav.logout') }}
        </Link>
    </DropdownMenuItem>
</template>
