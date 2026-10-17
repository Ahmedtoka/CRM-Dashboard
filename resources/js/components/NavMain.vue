<script setup lang="ts">
import NavBadge from '@/components/crm/NavBadge.vue';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { SidebarGroup, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarMenuSub, SidebarMenuSubButton, SidebarMenuSubItem } from '@/components/ui/sidebar';
import { useI18n } from '@/composables/useI18n';
import { formatCount } from '@/lib/format';
import { groupBadge, isGroupActive, isNavActive } from '@/lib/navMenu';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown } from 'lucide-vue-next';
import { reactive } from 'vue';

/**
 * The aubergine rail's menu (DESIGN §3 AppSidebar): groups that open (admin, supervisor) or a flat list with a divider
 * before «الإعدادات» (moderator, media buyer, content). Built by `buildNav`; this only renders it.
 */
defineProps<{ items: NavItem[]; label?: string }>();

const page = usePage<SharedData>();
const { t, locale } = useI18n();

/** User-toggled groups; untouched ones follow the current page. */
const toggled = reactive<Record<string, boolean>>({});
const isOpen = (item: NavItem) => toggled[item.href] ?? isGroupActive(item, page.url);

const row = 'relative group-data-[collapsible=icon]:overflow-visible h-10 gap-2.5 rounded-md px-2.5 font-medium text-rail-fg [&>svg]:size-[18px] [&>svg]:text-rail-fg-2 hover:bg-rail-2 hover:text-white';
const current = 'data-[active=true]:bg-rail-active data-[active=true]:font-semibold data-[active=true]:text-white data-[active=true]:[&>svg]:text-white';
</script>

<template>
    <SidebarGroup class="px-2.5 py-2.5">
        <nav :aria-label="label ?? t('nav.main_menu')">
            <SidebarMenu class="gap-0.5">
                <template v-for="item in items" :key="item.href">
                    <li v-if="item.separator" role="separator" class="mx-1.5 my-2 h-px bg-rail-line" />

                    <!-- «ابدأ من هنا ٣/٥»: the admin's onboarding pill until it is done. -->
                    <SidebarMenuItem v-else-if="item.progress" class="mb-1.5">
                        <SidebarMenuButton
                            as-child
                            :tooltip="t('nav.onboarding_progress', { done: item.progress.done, total: item.progress.total })"
                            :is-active="isNavActive(item, page.url)"
                            :class="[row, current, 'bg-rail-2 font-semibold']"
                        >
                            <Link :href="item.href" :aria-current="isNavActive(item, page.url) ? 'page' : undefined" data-onboarding-pill>
                                <component :is="item.icon" v-if="item.icon" />
                                <span class="flex-1 truncate group-data-[collapsible=icon]:hidden">{{ item.title }}</span>
                                <span class="h-1 w-10 overflow-hidden rounded bg-rail-line group-data-[collapsible=icon]:hidden" aria-hidden="true">
                                    <i class="block h-full rounded bg-[#D6A7E6]" :style="{ width: `${(item.progress.done / item.progress.total) * 100}%` }" />
                                </span>
                                <span class="text-xs font-medium tabular-nums text-rail-fg-2 group-data-[collapsible=icon]:hidden" aria-hidden="true">{{ formatCount(item.progress.done, locale) }}/{{ formatCount(item.progress.total, locale) }}</span>
                                <span class="sr-only">{{ t('nav.onboarding_progress', { done: item.progress.done, total: item.progress.total }) }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>

                    <Collapsible
                        v-else-if="item.children?.length"
                        as-child
                        :open="isOpen(item)"
                        class="group/collapsible"
                        @update:open="(v: boolean) => (toggled[item.href] = v)"
                    >
                        <SidebarMenuItem>
                            <CollapsibleTrigger as-child>
                                <SidebarMenuButton :tooltip="item.title" :class="[row, isGroupActive(item, page.url) ? 'text-white [&>svg]:text-white' : '']">
                                    <component :is="item.icon" v-if="item.icon" />
                                    <span class="flex-1 truncate group-data-[collapsible=icon]:hidden">{{ item.title }}</span>
                                    <!-- A closed group shows the sum of its children's waiting work. -->
                                    <NavBadge
                                        v-if="!isOpen(item)"
                                        tone="rail"
                                        :count="groupBadge(item).count"
                                        :urgent="groupBadge(item).urgent"
                                        class="group-data-[collapsible=icon]:absolute group-data-[collapsible=icon]:-top-0.5 group-data-[collapsible=icon]:end-0 group-data-[collapsible=icon]:h-4 group-data-[collapsible=icon]:min-w-4 group-data-[collapsible=icon]:text-[10px]"
                                    />
                                    <ChevronDown class="!size-4 opacity-70 group-data-[collapsible=icon]:hidden transition-transform duration-200 ease-out group-data-[state=open]/collapsible:rotate-180" aria-hidden="true" />
                                </SidebarMenuButton>
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <SidebarMenuSub class="mx-0 me-0 ms-[22px] mb-1.5 mt-0.5 gap-px border-s-0 px-0 ps-2">
                                    <SidebarMenuSubItem v-for="child in item.children" :key="child.href">
                                        <SidebarMenuSubButton
                                            as-child
                                            :is-active="isNavActive(child, page.url)"
                                            class="h-[34px] rounded-md px-2.5 text-[13.5px] text-rail-fg-2 hover:bg-rail-2 hover:text-rail-fg data-[active=true]:bg-rail-active data-[active=true]:font-semibold data-[active=true]:text-white"
                                        >
                                            <Link :href="child.href + (child.query ?? '')" :aria-current="isNavActive(child, page.url) ? 'page' : undefined">
                                                <span class="flex-1 truncate">{{ child.title }}</span>
                                                <NavBadge tone="rail" :count="child.badge ?? 0" :urgent="child.badgeUrgent" />
                                            </Link>
                                        </SidebarMenuSubButton>
                                    </SidebarMenuSubItem>
                                </SidebarMenuSub>
                            </CollapsibleContent>
                        </SidebarMenuItem>
                    </Collapsible>

                    <SidebarMenuItem v-else>
                        <SidebarMenuButton as-child :tooltip="item.title" :is-active="isNavActive(item, page.url)" :class="[row, current]">
                            <Link :href="item.href + (item.query ?? '')" :aria-current="isNavActive(item, page.url) ? 'page' : undefined">
                                <component :is="item.icon" v-if="item.icon" />
                                <span class="flex-1 truncate group-data-[collapsible=icon]:hidden">{{ item.title }}</span>
                                <NavBadge
                                    tone="rail"
                                    :count="item.badge ?? 0"
                                    :urgent="item.badgeUrgent"
                                    class="group-data-[collapsible=icon]:absolute group-data-[collapsible=icon]:-top-0.5 group-data-[collapsible=icon]:end-0 group-data-[collapsible=icon]:h-4 group-data-[collapsible=icon]:min-w-4 group-data-[collapsible=icon]:text-[10px]"
                                />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </template>
            </SidebarMenu>
        </nav>
    </SidebarGroup>
</template>
