<script setup lang="ts">
/** Accessible on/off switch in the Business Suite primary blue; the knob travels toward the reading end in RTL too. */
defineProps<{ label: string; disabled?: boolean }>();

const model = defineModel<boolean>({ required: true });
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="model"
        :aria-label="label"
        :disabled="disabled"
        class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50"
        :class="model ? 'bg-primary' : 'bg-input'"
        @click="model = !model"
    >
        <span
            class="inline-block size-4 rounded-full bg-background shadow-card transition-transform motion-reduce:transition-none"
            :class="model ? 'translate-x-[18px] rtl:-translate-x-[18px]' : 'translate-x-0.5 rtl:-translate-x-0.5'"
            aria-hidden="true"
        />
    </button>
</template>
