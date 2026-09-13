<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<!-- Social CRM mark: two overlapping chat bubbles (one inbox, many channels). -->
<template>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40" fill="none" :class="className" v-bind="$attrs" aria-hidden="true">
        <path
            fill="currentColor"
            d="M8 4h18a6 6 0 0 1 6 6v10a6 6 0 0 1-6 6H15.5L9 31.5V26H8a6 6 0 0 1-6-6V10a6 6 0 0 1 6-6Z"
        />
        <path fill="currentColor" opacity="0.45" d="M34 13.2A6 6 0 0 1 38 19v9a6 6 0 0 1-6 6h-1v4.5L25.5 34H18a6 6 0 0 1-4.8-2.4h13.3a7.5 7.5 0 0 0 7.5-7.5V13.2Z" />
        <circle cx="11" cy="15" r="2" fill="white" />
        <circle cx="17" cy="15" r="2" fill="white" />
        <circle cx="23" cy="15" r="2" fill="white" />
    </svg>
</template>
