import type { ThreadAnchor, ThreadView } from '@/lib/threadCache';
import { useVirtualizer } from '@tanstack/vue-virtual';
import { computed, onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue';

export type AnchoredView = ThreadView;

interface Options<T extends { key: string }> {
    scrollEl: Ref<HTMLElement | null>;
    rows: Ref<T[]>;
    estimate?: number;
    overscan?: number;
    /** Within this many px of the bottom counts as "at the bottom" (pinned). */
    pinThreshold?: number;
    paddingStart?: number;
    paddingEnd?: number;
    /** Rows that never serve as the anchor (the «load older» row at the top). */
    skipAnchor?: (row: T) => boolean;
}

/**
 * A bottom-anchored virtual list with measured heights (the chat thread, spec §1.3).
 *
 * The algorithm, in one place:
 * 1. Track. After every scroll (hers or ours) remember two things: `pinned` (the bottom is within
 *    `pinThreshold` px) and the anchor: the first row whose bottom is below the top edge (rows
 *    from `skipAnchor` excluded), as `{ key, delta = scrollTop − row.start }`. Row starts come
 *    from the virtualizer's measurements: real heights for rows already measured (cached by row
 *    key, so they survive index shifts), the estimate for the rest.
 * 2. Rows change (after the DOM is patched, before paint):
 *    - pinned → scroll to the end (a new message, a sent one, a switch to a pinned chat);
 *    - else the anchor row still exists → `scrollTop = start(key) + delta` from the NEW
 *      measurements. Prepended rows push `start(key)` down by their (estimated) height, so the
 *      anchor stays where it was on screen. Appended rows leave `start(key)` unchanged: a no-op.
 * 3. Settle. As the rows newly rendered above the viewport get their real heights, the
 *    virtualizer shifts `scrollTop` by each estimate→real delta (TanStack's default for an item
 *    above the scroll offset), so the anchor does not drift. While pinned, any growth of the
 *    list or shrink of the viewport scrolls to the end again.
 * `scrollHeight` diffs are never used: with estimated heights they are not the prepended height.
 */
export function useAnchoredVirtualList<T extends { key: string }>(options: Options<T>) {
    const pinThreshold = options.pinThreshold ?? 80;
    const virtualizer = useVirtualizer(
        computed(() => {
            const rows = options.rows.value;
            return {
                count: rows.length,
                getScrollElement: () => options.scrollEl.value,
                estimateSize: () => options.estimate ?? 72,
                overscan: options.overscan ?? 6,
                paddingStart: options.paddingStart ?? 0,
                paddingEnd: options.paddingEnd ?? 0,
                getItemKey: (index: number) => rows[index]?.key ?? index,
            };
        }),
    );

    const items = computed(() => virtualizer.value.getVirtualItems());
    const totalSize = computed(() => virtualizer.value.getTotalSize());

    const pinned = ref(true);
    let anchor: ThreadAnchor | null = null;
    /** A restored view whose anchor row is gone falls back to its raw scrollTop, once. */
    let fallbackTop: number | null = null;
    /** Index of the first row visible at the top edge (for the floating day label). */
    const topIndex = ref(0);

    function startOf(key: string): number | null {
        const rows = options.rows.value;
        const index = rows.findIndex((row) => row.key === key);
        if (index === -1) return null;
        virtualizer.value.getTotalSize(); // makes sure the measurements are current
        return virtualizer.value.measurementsCache[index]?.start ?? null;
    }

    function track(): void {
        const el = options.scrollEl.value;
        if (!el) return;
        const top = el.scrollTop;
        pinned.value = el.scrollHeight - top - el.clientHeight < pinThreshold;
        const v = virtualizer.value;
        v.getTotalSize();
        const rows = options.rows.value;
        const first = v.getVirtualItemForOffset(top);
        let index = first?.index ?? 0;
        topIndex.value = index;
        while (index < rows.length && options.skipAnchor?.(rows[index])) index++;
        const item = v.measurementsCache[index];
        anchor = item && rows[index] ? { key: rows[index].key, delta: top - item.start } : null;
    }

    function scrollToEnd(): void {
        const el = options.scrollEl.value;
        if (!el) return;
        el.scrollTop = el.scrollHeight;
        track();
    }

    function applyAnchor(): void {
        const el = options.scrollEl.value;
        if (!el) return;
        if (pinned.value) {
            scrollToEnd();
            return;
        }
        const start = anchor ? startOf(anchor.key) : null;
        if (start === null || !anchor) {
            if (fallbackTop !== null) el.scrollTop = fallbackTop;
            fallbackTop = null;
            track();
            return;
        }
        fallbackTop = null;
        const target = Math.max(0, start + anchor.delta);
        if (Math.abs(el.scrollTop - target) >= 1) el.scrollTop = target;
        track();
    }

    // 2. Rows changed: re-anchor after the DOM is patched, before the browser paints.
    watch(options.rows, applyAnchor, { flush: 'post' });
    // 3. While pinned, measured growth keeps the end in view.
    watch(totalSize, () => pinned.value && scrollToEnd(), { flush: 'post' });

    let resize: ResizeObserver | null = null;
    onMounted(() => {
        resize = new ResizeObserver(() => pinned.value && scrollToEnd());
        if (options.scrollEl.value) resize.observe(options.scrollEl.value);
        applyAnchor();
    });
    onBeforeUnmount(() => resize?.disconnect());

    /** Stable function ref for each row element (measured once on mount, then by ResizeObserver). */
    function measure(el: unknown): void {
        if (el instanceof Element) virtualizer.value.measureElement(el);
    }

    /** Where the view is, to come back to it (thread cache). */
    function viewState(): AnchoredView {
        return { scrollTop: options.scrollEl.value?.scrollTop ?? null, pinned: pinned.value, anchor };
    }

    /**
     * The next rows change shows this view: a saved one (anchor or bottom) or, with null, the
     * bottom. Call it before the rows change (a conversation switch); step 2 applies it.
     */
    function expect(view: AnchoredView | null): void {
        pinned.value = view ? view.pinned || (!view.anchor && view.scrollTop === null) : true;
        anchor = view?.anchor ?? null;
        fallbackTop = view && !view.pinned ? view.scrollTop : null;
    }

    /** The row at a scroll offset (px from the top of the list). */
    function indexAt(offset: number): number | null {
        return virtualizer.value.getVirtualItemForOffset(offset)?.index ?? null;
    }

    function scrollToIndex(index: number, align: 'start' | 'center' | 'end' | 'auto' = 'auto'): void {
        pinned.value = false;
        virtualizer.value.scrollToIndex(index, { align });
    }

    return { virtualizer, items, totalSize, pinned, topIndex, measure, track, scrollToEnd, viewState, expect, scrollToIndex, indexAt };
}
