import type { ConversationDetail, Message } from '@/types/crm';

/** Where the thread was scrolled to: the top visible row's key and how far into it (px). */
export interface ThreadAnchor {
    key: string;
    delta: number;
}

/** Where she left the thread's scroll. */
export interface ThreadView {
    scrollTop: number | null;
    pinned: boolean;
    anchor: ThreadAnchor | null;
}

/** A conversation as it was when she left it (spec §1.3 thread cache). */
export interface CachedThread {
    detail: ConversationDetail;
    messages: Message[];
    hasMore: boolean;
    /** Raw scrollTop when she left; a fallback when the anchor row is gone. */
    scrollTop: number | null;
    /** Within 80 px of the bottom when she left: reopen at the bottom. */
    pinned: boolean;
    /** The top visible row, so the view comes back on the same message even after older pages load. */
    anchor?: ThreadAnchor | null;
    /** When the server last confirmed this copy (ms). */
    at: number;
}

/**
 * A small LRU of opened conversations, no Vue. `get` and `set` count as a use; the least
 * recently used entry falls out when a 21st is stored. A Map keeps insertion order, so the
 * first key is always the oldest.
 */
export class ThreadCache {
    private readonly entries = new Map<number, CachedThread>();

    constructor(private readonly max = 20) {}

    get(id: number): CachedThread | undefined {
        const entry = this.entries.get(id);
        if (entry) {
            this.entries.delete(id);
            this.entries.set(id, entry);
        }
        return entry;
    }

    /** Like `get`, without counting as a use (realtime patches, freshness checks). */
    peek(id: number): CachedThread | undefined {
        return this.entries.get(id);
    }

    set(id: number, value: CachedThread): void {
        this.entries.delete(id);
        this.entries.set(id, value);
        while (this.entries.size > this.max) {
            const oldest = this.entries.keys().next().value;
            if (oldest === undefined) break;
            this.entries.delete(oldest);
        }
    }

    /** Change a cached entry in place (a realtime message or patch for a chat that is not open). */
    patch(id: number, fn: (value: CachedThread) => void): void {
        const entry = this.entries.get(id);
        if (entry) fn(entry);
    }

    delete(id: number): void {
        this.entries.delete(id);
    }

    keys(): number[] {
        return [...this.entries.keys()];
    }
}
