<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/** Thin bar under the top edge between an Inertia visit's start and finish; a visit faster than 150 ms never shows it. */
const DELAY_MS = 150;
const visible = ref(false);
const width = ref(0);
let delay: number | undefined;
let creep: number | undefined;
let hide: number | undefined;
let offStart: (() => void) | undefined;
let offFinish: (() => void) | undefined;

function clear(): void {
    window.clearTimeout(delay);
    window.clearInterval(creep);
    window.clearTimeout(hide);
}

function begin(): void {
    clear();
    delay = window.setTimeout(() => {
        visible.value = true;
        width.value = 12;
        creep = window.setInterval(() => (width.value = Math.min(90, width.value + (90 - width.value) * 0.15)), 200);
    }, DELAY_MS);
}

function end(): void {
    clear();
    if (!visible.value) return;
    width.value = 100;
    hide = window.setTimeout(() => {
        visible.value = false;
        width.value = 0;
    }, 200);
}

onMounted(() => {
    offStart = router.on('start', begin);
    offFinish = router.on('finish', end);
});
onBeforeUnmount(() => {
    offStart?.();
    offFinish?.();
    clear();
});
</script>

<template>
    <div v-if="visible" class="pointer-events-none fixed inset-x-0 top-0 z-[100] h-0.5" role="progressbar" aria-hidden="true">
        <div class="h-full bg-primary transition-[width] duration-200 ease-out rtl:float-right" :style="{ width: `${width}%` }" />
    </div>
</template>
