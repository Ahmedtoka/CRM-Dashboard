<script setup lang="ts">
/**
 * A secondary row / inline action as an icon (F7): the label is the tooltip and the accessible name, so a
 * row keeps its actions without a wall of text buttons. Primary page actions keep text buttons.
 */
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { computed, type Component, type HTMLAttributes } from 'vue';

const props = withDefaults(
    defineProps<{
        label: string;
        icon: Component;
        variant?: 'ghost' | 'outline' | 'primary' | 'destructive';
        size?: 'sm' | 'md';
        loading?: boolean;
        disabled?: boolean;
        /** Renders an Inertia link instead of a button. */
        href?: string;
        class?: HTMLAttributes['class'];
    }>(),
    { variant: 'ghost', size: 'md', loading: false, disabled: false, href: undefined, class: undefined },
);
const emit = defineEmits<{ click: [event: MouseEvent] }>();

const VARIANTS = {
    ghost: 'text-muted-foreground hover:bg-muted hover:text-foreground',
    outline: 'border border-input bg-card text-foreground hover:bg-muted',
    primary: 'text-primary hover:bg-primary/10',
    destructive: 'text-destructive hover:bg-destructive/10',
} as const;

const classes = computed(() =>
    cn(
        'inline-flex shrink-0 items-center justify-center rounded-md transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none',
        props.size === 'sm' ? 'size-7 [&_svg]:size-3.5' : 'size-8 [&_svg]:size-4',
        VARIANTS[props.variant],
        props.class,
    ),
);
const inactive = computed(() => props.disabled || props.loading);

function onClick(event: MouseEvent): void {
    if (inactive.value) return;
    emit('click', event);
}
</script>

<template>
    <TooltipProvider :delay-duration="300">
        <Tooltip>
            <TooltipTrigger as-child>
                <Link v-if="href" :href="href" :class="classes" :aria-label="label" data-icon-action>
                    <component :is="icon" aria-hidden="true" />
                </Link>
                <button
                    v-else
                    type="button"
                    :class="classes"
                    :aria-label="label"
                    :disabled="inactive"
                    :aria-busy="loading || undefined"
                    data-icon-action
                    @click="onClick"
                >
                    <LoaderCircle v-if="loading" class="animate-spin" aria-hidden="true" />
                    <component :is="icon" v-else aria-hidden="true" />
                </button>
            </TooltipTrigger>
            <TooltipContent class="px-2 py-1 text-xs">{{ label }}</TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
