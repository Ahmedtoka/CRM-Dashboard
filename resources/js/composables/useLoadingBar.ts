import { readonly, ref } from 'vue';

/**
 * One global top loading bar. Every Inertia visit (app.ts) and every non-silent request through axios (useApi's
 * instance and the default instance, see attachLoadingBar) calls start()/done(); the bar is up while at least one is
 * pending. It appears only after SHOW_DELAY_MS (fast work never flashes) and, once shown, stays at least
 * MIN_VISIBLE_MS (no flicker), trickles towards 90%, completes to 100% and hides.
 */
export const SHOW_DELAY_MS = 200;
export const MIN_VISIBLE_MS = 200;
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
let completeTimer: ReturnType<typeof setTimeout> | undefined;
let shownAt = 0;

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
    shownAt = Date.now();
    resetWithoutAnimation();
    startTrickle();
}

function start(): void {
    pending.value++;

    // Waiting out the minimum visible time: just keep going.
    if (completeTimer !== undefined) {
        clearTimeout(completeTimer);
        completeTimer = undefined;
        return;
    }

    if (active.value) {
        // Completing (100%, hide scheduled): cancel the hide and restart from the beginning.
        if (trickle === undefined) {
            clearTimeout(hideTimer);
            hideTimer = undefined;
            shownAt = Date.now();
            resetWithoutAnimation();
            startTrickle();
        }
        return;
    }

    if (showTimer === undefined) showTimer = setTimeout(show, SHOW_DELAY_MS);
}

function complete(): void {
    completeTimer = undefined;
    if (pending.value > 0) return;
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

function done(): void {
    if (pending.value === 0) return;
    pending.value--;
    if (pending.value > 0) return;

    if (!active.value) {
        // Finished before the delay: never show the bar.
        clearTimeout(showTimer);
        showTimer = undefined;
        return;
    }

    const remaining = MIN_VISIBLE_MS - (Date.now() - shownAt);
    if (remaining > 0) {
        clearTimeout(completeTimer);
        completeTimer = setTimeout(complete, remaining);
    } else {
        complete();
    }
}

/** Tests only: back to a clean idle state. */
export function resetLoadingBar(): void {
    clearInterval(trickle);
    clearTimeout(showTimer);
    clearTimeout(hideTimer);
    clearTimeout(completeTimer);
    trickle = showTimer = hideTimer = completeTimer = undefined;
    pending.value = 0;
    progress.value = 0;
    active.value = false;
    instant.value = false;
    shownAt = 0;
}

export function useLoadingBar() {
    return { active: readonly(active), progress: readonly(progress), instant: readonly(instant), pending: readonly(pending), start, done };
}
