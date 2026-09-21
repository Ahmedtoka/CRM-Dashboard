<script setup lang="ts">
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useInitials } from '@/composables/useInitials';
import { useI18n } from '@/composables/useI18n';
import type { BreadcrumbItemType, SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItemType[] }>(), { breadcrumbs: () => [] });

const page = usePage<SharedData>();
const { t, locale, setLocale } = useI18n();
const languageOptions = [
    { value: 'ar', label: 'ع' },
    { value: 'en', label: 'EN' },
] as const;
const { getInitials } = useInitials();
</script>

<template>
    <header class="sticky top-0 z-30 flex h-14 shrink-0 items-center gap-3 border-b bg-card px-3 shadow-card md:px-4">
        <SidebarTrigger class="-ms-1" />
        <Breadcrumb v-if="breadcrumbs.length" class="hidden min-w-0 sm:block">
            <BreadcrumbList class="flex-nowrap">
                <template v-for="(item, index) in breadcrumbs" :key="index">
                    <BreadcrumbItem class="min-w-0">
                        <BreadcrumbPage v-if="index === breadcrumbs.length - 1" class="truncate font-semibold">{{ item.title }}</BreadcrumbPage>
                        <BreadcrumbLink v-else :href="item.href" class="truncate">{{ item.title }}</BreadcrumbLink>
                    </BreadcrumbItem>
                    <BreadcrumbSeparator v-if="index !== breadcrumbs.length - 1" />
                </template>
            </BreadcrumbList>
        </Breadcrumb>
        <div class="flex min-w-0 flex-1 justify-center"><slot name="search" /></div>
        <div class="flex shrink-0 items-center gap-1"><slot name="actions" /></div>
        <!-- One-click interface language (ع / EN); the choice is saved per user. -->
        <div
            class="flex shrink-0 items-center rounded-full border border-border bg-background p-0.5 text-xs font-semibold"
            role="group"
            :aria-label="t('nav.language_switcher')"
        >
            <button
                v-for="option in languageOptions"
                :key="option.value"
                type="button"
                class="rounded-full px-2.5 py-1 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :class="locale === option.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'"
                :aria-pressed="locale === option.value"
                :lang="option.value"
                @click="setLocale(option.value)"
            >
                {{ option.label }}
            </button>
        </div>
        <DropdownMenu>
            <DropdownMenuTrigger
                class="flex size-9 items-center justify-center rounded-full text-xs font-bold text-white"
                :style="{ backgroundColor: page.props.auth.user.color || 'hsl(var(--primary))' }"
                :aria-label="t('nav.account')"
            >
                {{ getInitials(page.props.auth.user.name) }}
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-64">
                <UserMenuContent :user="page.props.auth.user" />
            </DropdownMenuContent>
        </DropdownMenu>
    </header>
</template>
