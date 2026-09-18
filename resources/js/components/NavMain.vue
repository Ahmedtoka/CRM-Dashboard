<script setup lang="ts">
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';

defineProps<{
    items: NavItem[];
    label?: string;
}>();

const page = usePage<SharedData>();

const isActive = (href: string) => {
    const path = page.url.split('?')[0];

    return path === href || path.startsWith(`${href}/`);
};

/** Consecutive children sharing a `section` render under one small heading. */
const sectionsOf = (children: NavItem[]) => {
    const sections: { label?: string; items: NavItem[] }[] = [];
    for (const child of children) {
        const last = sections[sections.length - 1];
        if (last && last.label === child.section) {
            last.items.push(child);
        } else {
            sections.push({ label: child.section, items: [child] });
        }
    }

    return sections;
};
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel v-if="label">{{ label }}</SidebarGroupLabel>
        <SidebarMenu>
            <template v-for="item in items" :key="item.href">
                <Collapsible v-if="item.children?.length" as-child :default-open="isActive(item.href)" class="group/collapsible">
                    <SidebarMenuItem>
                        <CollapsibleTrigger as-child>
                            <SidebarMenuButton
                                :tooltip="item.title"
                                :is-active="isActive(item.href)"
                                class="h-10 gap-3 rounded-md font-medium [&>svg]:size-5 data-[active=true]:bg-surface-accent data-[active=true]:text-primary"
                            >
                                <component :is="item.icon" v-if="item.icon" />
                                <span>{{ item.title }}</span>
                                <ChevronRight
                                    class="rtl-flip ms-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90 rtl:group-data-[state=open]/collapsible:-rotate-90"
                                />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <SidebarMenuSub>
                                <template v-for="(section, si) in sectionsOf(item.children)" :key="`${item.href}-${si}`">
                                    <li v-if="section.label" :class="si > 0 ? 'mt-2' : ''">
                                        <span
                                            :id="`nav-section-${item.href.replace(/\W/g, '')}-${si}`"
                                            class="block px-2 pb-1 text-[11px] font-semibold text-muted-foreground"
                                        >
                                            {{ section.label }}
                                        </span>
                                        <ul
                                            role="group"
                                            :aria-labelledby="`nav-section-${item.href.replace(/\W/g, '')}-${si}`"
                                            class="flex flex-col gap-1"
                                        >
                                            <SidebarMenuSubItem v-for="child in section.items" :key="child.href">
                                                <SidebarMenuSubButton as-child :is-active="isActive(child.href)">
                                                    <Link :href="child.href" :aria-current="isActive(child.href) ? 'page' : undefined">
                                                        <span>{{ child.title }}</span>
                                                    </Link>
                                                </SidebarMenuSubButton>
                                            </SidebarMenuSubItem>
                                        </ul>
                                    </li>
                                    <template v-else>
                                        <SidebarMenuSubItem v-for="child in section.items" :key="child.href">
                                            <SidebarMenuSubButton as-child :is-active="isActive(child.href)">
                                                <Link :href="child.href" :aria-current="isActive(child.href) ? 'page' : undefined">
                                                    <span>{{ child.title }}</span>
                                                </Link>
                                            </SidebarMenuSubButton>
                                        </SidebarMenuSubItem>
                                    </template>
                                </template>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </SidebarMenuItem>
                </Collapsible>

                <SidebarMenuItem v-else>
                    <SidebarMenuButton
                        as-child
                        :tooltip="item.title"
                        :is-active="isActive(item.href)"
                        class="h-10 gap-3 rounded-md font-medium [&>svg]:size-5 data-[active=true]:bg-surface-accent data-[active=true]:text-primary"
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" v-if="item.icon" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </template>
        </SidebarMenu>
    </SidebarGroup>
</template>
