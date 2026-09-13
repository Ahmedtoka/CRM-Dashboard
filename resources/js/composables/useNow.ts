import { onScopeDispose, ref, type Ref } from 'vue';

// One shared clock for wait timers and window countdowns; ticks every 30 s.
const now = ref(Date.now());
let subscribers = 0;
let timer: number | undefined;

export const NOW_TICK_MS = 30000;

export function useNow(): Readonly<Ref<number>> {
    subscribers++;

    if (timer === undefined) {
        now.value = Date.now();
        timer = window.setInterval(() => (now.value = Date.now()), NOW_TICK_MS);
    }

    onScopeDispose(() => {
        subscribers--;

        if (subscribers === 0 && timer !== undefined) {
            window.clearInterval(timer);
            timer = undefined;
        }
    });

    return now;
}
