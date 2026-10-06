<script setup lang="ts">
import FreshnessChip from '@/components/crm/FreshnessChip.vue';
import { useI18n } from '@/composables/useI18n';
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        /** The full trail including this page; the parents show as links on phones (the top bar shows them from sm up). */
        breadcrumbs?: { label: string; href?: string }[];
        /** ISO timestamp of the data on screen; one freshness chip per page. */
        freshness?: string | null;
        /** Stick under the top bar and publish its height so sticky table headers sit below it. */
        sticky?: boolean;
    }>(),
    { description: undefined, breadcrumbs: () => [], freshness: null, sticky: false },
);

const { t } = useI18n();
const root = ref<HTMLElement | null>(null);
const parents = computed(() => props.breadcrumbs.slice(0, -1));

/** The app top bar (AppTopBar h-14). */
const TOP_BAR_PX = 56;
let observer: ResizeObserver | undefined;

function publish(): void {
    if (root.value) document.documentElement.style.setProperty('--page-header-h', `${TOP_BAR_PX + root.value.offsetHeight}px`);
}

onMounted(() => {
    if (!props.sticky || !root.value) return;
    publish();
    if (typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(publish);
        observer.observe(root.value);
    }
});

onBeforeUnmount(() => {
    observer?.disconnect();
    if (props.sticky) document.documentElement.style.removeProperty('--page-header-h');
});
</script>

<template>
    <header
        ref="root"
        class="mb-1 space-y-1"
        :class="sticky ? 'sticky top-14 z-20 -mx-3 bg-background/95 px-3 py-2 backdrop-blur supports-[backdrop-filter]:bg-background/80 md:-mx-6 md:px-6' : ''"
    >
        <nav v-if="parents.length" class="flex min-w-0 items-center gap-1 text-2xs text-muted-foreground sm:hidden" :aria-label="t('ui.breadcrumbs')">
            <template v-for="(crumb, index) in parents" :key="index">
                <Link v-if="crumb.href" :href="crumb.href" class="truncate hover:text-foreground hover:underline">{{ crumb.label }}</Link>
                <span v-else class="truncate">{{ crumb.label }}</span>
                <ChevronRight class="rtl-flip size-3 shrink-0" aria-hidden="true" />
            </template>
        </nav>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <div class="min-w-[min(100%,9rem)] flex-1">
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <h1 class="truncate text-xl font-bold text-foreground">{{ title }}</h1>
                    <FreshnessChip :at="freshness" />
                </div>
                <p v-if="description" class="text-xs text-muted-foreground">{{ description }}</p>
            </div>
            <div v-if="$slots.default" class="flex flex-wrap items-center gap-2">
                <slot />
            </div>
        </div>
    </header>
</template>
