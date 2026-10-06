import { ref, watch, type Ref } from 'vue';

export type Density = 'comfortable' | 'compact';

const PREFIX = 'crm.density.';

/** Table density (مريح / مضغوط) remembered per table in this browser; storage may be blocked, so every access is guarded. */
export function useDensity(id: string, fallback: Density = 'comfortable'): Ref<Density> {
    let initial: Density = fallback;
    try {
        const stored = localStorage.getItem(PREFIX + id);
        if (stored === 'comfortable' || stored === 'compact') initial = stored;
    } catch {
        // Storage blocked: keep the fallback.
    }

    const density = ref<Density>(initial);
    watch(
        density,
        (value) => {
            try {
                localStorage.setItem(PREFIX + id, value);
            } catch {
                // Storage blocked: the choice just won't persist.
            }
        },
        { flush: 'sync' },
    );

    return density;
}
