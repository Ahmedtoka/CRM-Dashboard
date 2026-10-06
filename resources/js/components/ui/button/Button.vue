<script setup lang="ts">
import { cn } from '@/lib/utils';
import { LoaderCircle } from 'lucide-vue-next';
import { Primitive, type PrimitiveProps } from 'radix-vue';
import { computed, type HTMLAttributes } from 'vue';
import { buttonVariants, type ButtonVariants } from '.';

interface Props extends PrimitiveProps {
    variant?: ButtonVariants['variant'];
    size?: ButtonVariants['size'];
    class?: HTMLAttributes['class'];
    /** One submit state everywhere: spinner over the label, disabled, same width. */
    loading?: boolean;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), { as: 'button', loading: false, disabled: false });

const isDisabled = computed(() => props.disabled || props.loading);
</script>

<template>
    <Primitive
        :as="as"
        :as-child="asChild"
        :class="cn(buttonVariants({ variant, size }), 'relative', props.class)"
        :disabled="as === 'button' && !asChild ? isDisabled : undefined"
        :aria-disabled="isDisabled || undefined"
        :aria-busy="loading || undefined"
    >
        <slot v-if="asChild" />
        <template v-else>
            <span v-if="loading" class="absolute inset-0 flex items-center justify-center" aria-hidden="true">
                <LoaderCircle class="animate-spin" />
            </span>
            <span data-button-label class="contents" :class="{ invisible: loading }"><slot /></span>
        </template>
    </Primitive>
</template>
