<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <header class="mb-1 flex flex-wrap items-center gap-x-4 gap-y-2">
        <div class="min-w-0 flex-1">
            <h1 class="truncate text-xl font-bold text-foreground">{{ title }}</h1>
            <p v-if="description" class="text-xs text-muted-foreground">{{ description }}</p>
        </div>
        <div v-if="$slots.default" class="flex flex-wrap items-center gap-2">
            <slot />
        </div>
    </header>
</template>
