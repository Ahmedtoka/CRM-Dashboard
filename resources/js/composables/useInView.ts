import { computed, onBeforeUnmount, onMounted, ref, type ComputedRef, type Ref } from 'vue';

/**
 * Whether an element is worth animating: on screen (IntersectionObserver) and the tab is shown.
 * Endless animations pause when `active` is false (spec §2.2, "Faster").
 */
export function useInView(el: Ref<HTMLElement | null>): { active: ComputedRef<boolean> } {
    const intersecting = ref(true);
    const shown = ref(typeof document === 'undefined' ? true : !document.hidden);
    let observer: IntersectionObserver | undefined;

    const onVisibility = () => (shown.value = !document.hidden);

    onMounted(() => {
        document.addEventListener('visibilitychange', onVisibility);
        if (typeof IntersectionObserver !== 'function' || el.value === null) return;
        observer = new IntersectionObserver((entries) => {
            const last = entries[entries.length - 1];
            if (last) intersecting.value = last.isIntersecting;
        });
        observer.observe(el.value);
    });

    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onVisibility);
        observer?.disconnect();
    });

    return { active: computed(() => intersecting.value && shown.value) };
}
