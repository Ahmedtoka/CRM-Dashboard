<script setup lang="ts">
/**
 * Segmented view switch (DESIGN §3 Segmented): icon options with a tooltip and an accessible name; the current one is
 * raised on the sunken track. An option with an `href` is a link (the view lives in the URL); without one it is a
 * pressed-state button and the page keeps the choice (`select`).
 */
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Link } from '@inertiajs/vue3';
import type { Component } from 'vue';

defineProps<{ options: { key: string; label: string; icon: Component; href?: string }[]; current: string; label: string }>();
const emit = defineEmits<{ select: [key: string] }>();

const base =
    'inline-grid size-11 place-items-center md:h-8 md:w-9 rounded-[6px] text-fg-2 transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
</script>

<template>
    <TooltipProvider :delay-duration="300">
        <component :is="options.some((o) => o.href) ? 'nav' : 'div'" :role="options.some((o) => o.href) ? undefined : 'group'" class="inline-flex h-12 shrink-0 items-center gap-0.5 rounded-md bg-sunken p-0.5 md:h-9" :aria-label="label">
            <Tooltip v-for="o in options" :key="o.key">
                <TooltipTrigger as-child>
                    <Link
                        v-if="o.href"
                        :href="o.href"
                        preserve-scroll
                        :data-view="o.key"
                        :aria-label="o.label"
                        :aria-current="current === o.key ? 'page' : undefined"
                        :class="[base, current === o.key ? 'bg-card text-foreground shadow-e1' : 'hover:text-foreground']"
                    >
                        <component :is="o.icon" class="size-4" aria-hidden="true" />
                    </Link>
                    <button
                        v-else
                        type="button"
                        :data-view="o.key"
                        :aria-label="o.label"
                        :aria-pressed="current === o.key"
                        :class="[base, current === o.key ? 'bg-card text-foreground shadow-e1' : 'hover:text-foreground']"
                        @click="emit('select', o.key)"
                    >
                        <component :is="o.icon" class="size-4" aria-hidden="true" />
                    </button>
                </TooltipTrigger>
                <TooltipContent>{{ o.label }}</TooltipContent>
            </Tooltip>
        </component>
    </TooltipProvider>
</template>
