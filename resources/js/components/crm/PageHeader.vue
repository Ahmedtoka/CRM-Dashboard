<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <header class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <div class="min-w-0 flex-1">
            <h1 class="truncate text-lg font-semibold text-foreground">{{ title }}</h1>
            <p v-if="description" class="text-xs text-muted-foreground">{{ description }}</p>
        </div>
        <div v-if="$slots.default" class="flex flex-wrap items-center gap-2">
            <slot />
        </div>
    </header>
</template>
