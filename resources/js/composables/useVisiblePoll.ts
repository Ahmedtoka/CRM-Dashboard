import { onBeforeUnmount, onMounted } from 'vue';

/** Calls `fn` every `ms` while the tab is visible; the timer stops on unmount. A tab that becomes visible again polls at once. */
export function useVisiblePoll(fn: () => void, ms: number): void {
    let timer: number | undefined;

    const tick = () => {
        if (document.visibilityState === 'visible') fn();
    };
    const onVisible = () => {
        if (document.visibilityState === 'visible') fn();
    };

    onMounted(() => {
        timer = window.setInterval(tick, ms);
        document.addEventListener('visibilitychange', onVisible);
    });
    onBeforeUnmount(() => {
        window.clearInterval(timer);
        document.removeEventListener('visibilitychange', onVisible);
    });
}
