import { reactive } from 'vue';

/**
 * Which notes (`n-<id>`) and note groups (`g-<first note id>`) are open. Module-level, so the
 * state survives a row being remounted (virtualised timeline, thread switch and back) for as
 * long as the page is open, as the spec asks.
 */
export const expandedNotes = reactive(new Set<string>());

export function toggleExpanded(key: string): void {
    if (expandedNotes.has(key)) expandedNotes.delete(key);
    else expandedNotes.add(key);
}
