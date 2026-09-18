import type { Message } from '@/types/crm';

const RUN_WINDOW_MS = 2 * 60 * 1000;

// A failed send or a not-yet-stored attachment must render on its own bubble (with its
// failed banner / retry / ticks), never folded into a grid where none of that shows.
export function isImageOnly(m: Message): boolean {
    return (
        !m.body &&
        m.status !== 'failed' &&
        m.attachments.length > 0 &&
        m.attachments.every((a) => a.type === 'image' && a.status === 'stored')
    );
}

/** Consecutive image-only messages from the same sender within 2 minutes render as one grid (spec §1.5). */
export function groupImageRuns<T extends { message?: Message }>(entries: T[]): Array<T & { group?: Message[] }> {
    const out: Array<T & { group?: Message[] }> = [];
    for (const entry of entries) {
        const last = out[out.length - 1];
        const m = entry.message;
        const head = last?.message;
        if (
            m &&
            head &&
            isImageOnly(m) &&
            isImageOnly(head) &&
            m.direction === head.direction &&
            m.sender_type === head.sender_type &&
            (m.user?.id ?? null) === (head.user?.id ?? null) &&
            Math.abs(Date.parse(m.created_at ?? '') - Date.parse(head.created_at ?? '')) <= RUN_WINDOW_MS
        ) {
            last.group = [...(last.group ?? []), m];
            continue;
        }
        out.push({ ...entry });
    }
    return out;
}
