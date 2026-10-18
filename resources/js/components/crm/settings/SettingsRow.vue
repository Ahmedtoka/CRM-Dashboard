<script setup lang="ts">
/**
 * One setting (DESIGN §3 SettingsRow): a clear title, one line on what it does, and the control at the far end. Rows
 * stack inside a SettingsPanel with a hairline between them; under 640px the control drops under the text.
 */
withDefaults(defineProps<{ title: string; description?: string; for?: string; stack?: boolean }>(), {
    description: undefined,
    for: undefined,
    stack: false,
});
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-line py-3.5 last:border-b-0"
        :class="stack ? 'flex-col items-stretch' : ''"
        data-settings-row
    >
        <div class="min-w-[min(100%,14rem)] flex-1">
            <label v-if="$props.for" :for="$props.for" class="block text-sm font-semibold">{{ title }}</label>
            <p v-else class="text-sm font-semibold">{{ title }}</p>
            <p v-if="description" class="mt-0.5 text-13 text-fg-2">{{ description }}</p>
            <slot name="below" />
        </div>
        <div v-if="$slots.default" class="flex shrink-0 flex-wrap items-center gap-2" :class="stack ? 'w-full' : ''"><slot /></div>
    </div>
</template>
