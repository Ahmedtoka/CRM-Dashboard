<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{ icon?: Component; title: string; body?: string }>();
</script>

<template>
    <div class="flex flex-1 flex-col items-center justify-center gap-2 p-8 text-center">
        <div v-if="icon" class="mb-1 flex size-10 items-center justify-center rounded-full bg-muted text-muted-foreground">
            <component :is="icon" class="size-5" aria-hidden="true" />
        </div>
        <p class="text-sm font-medium text-foreground">{{ title }}</p>
        <p v-if="body" class="max-w-xs text-xs text-muted-foreground">{{ body }}</p>
        <slot />
    </div>
</template>
