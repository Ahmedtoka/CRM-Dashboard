<script setup lang="ts">
import type { Point } from '@/lib/board/layout';
import { onBeforeUnmount, onMounted, ref } from 'vue';

// A customer who was just called, walking from her lounge seat to her window. Drawn only
// while she walks (the board removes her after BOARD_MOVE_MS); never with reduced motion.
defineProps<{ from: Point; to: Point; ticket: number; colour: string; alt: boolean }>();

const there = ref(false);
let frame = 0;

onMounted(() => {
    // Two frames: the first paints her at her seat, the second starts the walk.
    frame = requestAnimationFrame(() => (frame = requestAnimationFrame(() => (there.value = true))));
});
onBeforeUnmount(() => cancelAnimationFrame(frame));
</script>

<template>
    <div
        class="walker"
        :class="{ there }"
        :style="{ transform: `translate(${(there ? to : from).x}px, ${(there ? to : from).y}px)` }"
        aria-hidden="true"
    >
        <span class="tk num">{{ ticket }}</span>
        <svg :style="{ color: colour }"><use :href="alt ? '#br-g-girl2' : '#br-g-girl'" /></svg>
    </div>
</template>
