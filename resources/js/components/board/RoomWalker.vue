<script setup lang="ts">
import type { Point } from '@/lib/board/layout';
import { onBeforeUnmount, onMounted, ref } from 'vue';

// A customer who was just called, walking from her lounge seat to her window along a curve
// (a lift halfway), then settling to the window's size. Only `transform` moves, through the Web
// Animations API; `will-change` is set for the walk and dropped when she arrives. Drawn only while
// she walks (the board removes her after BOARD_MOVE_MS); never with reduced motion (no move is made).
const props = defineProps<{ from: Point; to: Point; ticket: number; colour: string; alt: boolean }>();

const el = ref<HTMLElement | null>(null);
let walk: Animation | undefined;

const WALK_MS = 900;
const LIFT = 40;

onMounted(() => {
    const node = el.value;
    if (node === null || typeof node.animate !== 'function') return;

    const mid = { x: (props.from.x + props.to.x) / 2, y: Math.min(props.from.y, props.to.y) - LIFT };
    node.style.willChange = 'transform';
    node.classList.add('walking');
    walk = node.animate(
        [
            { transform: `translate(${props.from.x}px, ${props.from.y}px) scale(1)` },
            { transform: `translate(${mid.x}px, ${mid.y}px) scale(1.06)`, offset: 0.5 },
            { transform: `translate(${props.to.x}px, ${props.to.y}px) scale(0.8)` },
        ],
        { duration: WALK_MS, easing: 'cubic-bezier(.22,.8,.24,1)', fill: 'forwards' },
    );
    walk.onfinish = () => {
        node.style.willChange = '';
        node.classList.remove('walking');
    };
});

onBeforeUnmount(() => walk?.cancel());
</script>

<template>
    <div ref="el" class="walker" :style="{ transform: `translate(${to.x}px, ${to.y}px) scale(0.8)` }" aria-hidden="true">
        <span class="tk num">{{ ticket }}</span>
        <svg :style="{ color: colour }"><use :href="alt ? '#br-g-girl2' : '#br-g-girl'" /></svg>
    </div>
</template>
