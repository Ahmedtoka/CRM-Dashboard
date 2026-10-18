<script setup lang="ts">
/**
 * One decision row (DESIGN §3 DecisionCard): the creative at 4:5, the name, **why** in one sentence with the figures,
 * spend · orders · return, and the actions at the end. Rows stack inside an AdsPanel with hairlines between them;
 * on a phone the actions wrap under the text.
 */
withDefaults(defineProps<{ focused?: boolean }>(), { focused: false });
</script>

<template>
    <div
        class="grid grid-cols-[52px_minmax(0,1fr)] items-center gap-x-3.5 gap-y-2.5 border-b border-line px-4 py-3.5 last:border-b-0 sm:grid-cols-[52px_minmax(0,1fr)_auto]"
        :class="focused ? 'bg-brand-soft/60 outline outline-2 -outline-offset-2 outline-ring' : ''"
    >
        <div class="h-16 w-[52px] self-start overflow-hidden rounded-md bg-sunken"><slot name="thumb" /></div>
        <div class="min-w-0 space-y-0.5"><slot /></div>
        <div v-if="$slots.actions" class="col-span-2 flex flex-wrap items-center justify-end gap-1.5 sm:col-span-1"><slot name="actions" /></div>
    </div>
</template>
