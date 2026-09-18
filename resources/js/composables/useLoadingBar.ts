import { readonly, ref } from 'vue';

/**
 * One global top loading bar (human bot flow Task 5). Every Inertia visit and
 * every non-silent `useApi` request calls `start()` / `done()`; the bar stays
 * up while any of them is pending, trickles towards 90%, then completes to 100%
 * and hides. Module-level so every caller shares the same counter.
 *
 * Fix round 1: the bar only appears once work has been pending for SHOW_DELAY_MS,
 * so fast requests never flash it; slow ones still get feedback. A restart while
 * the bar is completing jumps back to the start without animating backwards.
 */
export const SHOW_DELAY_MS = 150;
const HIDE_AFTER_MS = 220;
const START_PROGRESS = 8;

const pending = ref(0);
const progress = ref(0);
const active = ref(false);
/** True for one frame while progress resets, so the bar never animates from 100% back to the start. */
const instant = ref(false);

let trickle: ReturnType<typeof setInterval> | undefined;
let showTimer: ReturnType<typeof setTimeout> | undefined;
let hideTimer: ReturnType<typeof setTimeout> | undefined;

function startTrickle(): void {
    clearInterval(trickle);
    trickle = setInterval(() => {
        progress.value = Math.min(90, progress.value + (90 - progress.value) * 0.12);
    }, 200);
}

function resetWithoutAnimation(): void {
    instant.value = true;
    progress.value = START_PROGRESS;
    const release = () => (instant.value = false);
    if (typeof requestAnimationFrame === 'function') requestAnimationFrame(() => requestAnimationFrame(release));
    else setTimeout(release, 32);
}

function show(): void {
    showTimer = undefined;
    if (pending.value === 0) return;
    active.value = true;
    resetWithoutAnimation();
    startTrickle();
}

function start(): void {
    pending.value++;

    if (active.value) {
        // Completing (100%, hide scheduled): cancel the hide and restart from the beginning.
        if (trickle === undefined) {
            clearTimeout(hideTimer);
            hideTimer = undefined;
            resetWithoutAnimation();
            startTrickle();
        }
        return;
    }

    if (showTimer === undefined) showTimer = setTimeout(show, SHOW_DELAY_MS);
}

function done(): void {
    pending.value = Math.max(0, pending.value - 1);
    if (pending.value > 0) return;

    if (!active.value) {
        // Finished before the delay: never show the bar.
        clearTimeout(showTimer);
        showTimer = undefined;
        return;
    }

    clearInterval(trickle);
    trickle = undefined;
    progress.value = 100;
    clearTimeout(hideTimer);
    hideTimer = setTimeout(() => {
        hideTimer = undefined;
        if (pending.value === 0) {
            active.value = false;
            instant.value = true;
            progress.value = 0;
        }
    }, HIDE_AFTER_MS);
}

export function useLoadingBar() {
    return { active: readonly(active), progress: readonly(progress), instant: readonly(instant), start, done };
}
