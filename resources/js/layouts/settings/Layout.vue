<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useI18n } from '@/composables/useI18n';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const { t } = useI18n();

const sidebarNavItems = computed<NavItem[]>(() => [
    {
        title: t('nav.settings_layout.profile'),
        href: '/settings/profile',
    },
    {
        title: t('nav.settings_layout.password'),
        href: '/settings/password',
    },
    {
        title: t('nav.settings_layout.appearance'),
        href: '/settings/appearance',
    },
    {
        title: t('nav.settings_notifications'),
        href: '/settings/notifications',
    },
]);

const currentPath = window.location.pathname;
</script>

<template>
    <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
        <Heading :title="t('nav.settings_layout.title')" :description="t('nav.settings_layout.description')" />

        <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
            <aside class="w-full shrink-0 lg:w-56">
                <nav class="scrollbar-thin flex gap-1 overflow-x-auto rounded-lg bg-card p-1.5 shadow-card lg:flex-col lg:overflow-visible">
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="item.href"
                        variant="ghost"
                        :class="['shrink-0 justify-start rounded-md lg:w-full', currentPath === item.href ? 'bg-surface-accent text-primary hover:bg-surface-accent' : '']"
                        as-child
                    >
                        <Link :href="item.href">
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="lg:hidden" />

            <div class="min-w-0 max-w-4xl flex-1">
                <section class="space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
