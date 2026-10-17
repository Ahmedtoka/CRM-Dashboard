<script setup lang="ts">
import NavBadge from '@/components/crm/NavBadge.vue';
import { activeTabIndex, type SectionTab } from '@/lib/sectionTabs';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The one tab strip for every merge (DESIGN §3 SectionTabs, IA D1–D14): each tab is a link with its own URL, never
 * local state, so a tab can be bookmarked, shared and opened from a notification. A 2px mauve underline marks the
 * current one; the strip scrolls sideways on a phone and hides itself when the person may see one tab only.
 */
const props = defineProps<{ tabs: SectionTab[]; label: string }>();

const page = usePage();
const active = computed(() => activeTabIndex(props.tabs, page.url));
</script>

<template>
    <nav v-if="tabs.length > 1" :aria-label="label" class="scrollbar-none -mt-1 flex gap-0.5 overflow-x-auto border-b border-line">
        <Link
            v-for="(tab, i) in tabs"
            :key="tab.href"
            :href="tab.href"
            class="relative inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-t-md px-3 pb-[11px] pt-2.5 text-sm font-semibold transition-colors"
            :class="
                i === active
                    ? 'text-brand-ink after:absolute after:inset-x-2 after:-bottom-px after:h-0.5 after:rounded-full after:bg-brand'
                    : 'text-fg-2 hover:bg-surface-2 hover:text-foreground'
            "
            :aria-current="i === active ? 'page' : undefined"
            :data-tab="tab.href"
        >
            {{ tab.label }}
            <NavBadge :count="tab.badge ?? 0" :urgent="tab.urgent" class="h-[18px] min-w-[18px] text-[11px]" />
        </Link>
    </nav>
</template>
