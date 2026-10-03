import { cairoDayKey } from '@/lib/format';
import type { Message, Note } from '@/types/crm';

const RUN_WINDOW_MS = 2 * 60 * 1000;
const FIVE_MINUTES = 5 * 60 * 1000;

// A failed send or a not-yet-stored attachment must render on its own bubble (with its
// failed banner / retry / ticks), never folded into a grid where none of that shows.
export function isImageOnly(m: Message): boolean {
    return !m.body && m.status !== 'failed' && m.attachments.length > 0 && m.attachments.every((a) => a.type === 'image' && a.status === 'stored');
}

/** Consecutive image-only messages from the same sender within 2 minutes render as one grid (spec §1.5). */
export function groupImageRuns<T extends { message?: Message }>(entries: T[]): Array<T & { group?: Message[] }> {
    const out: Array<T & { group?: Message[] }> = [];
    for (const entry of entries) {
        const last = out[out.length - 1];
        if (entry.message && last?.message && foldsIntoRun(last.message, entry.message)) {
            last.group = [...(last.group ?? []), entry.message];
            continue;
        }
        out.push({ ...entry });
    }
    return out;
}

function foldsIntoRun(head: Message, m: Message): boolean {
    return (
        isImageOnly(m) &&
        isImageOnly(head) &&
        m.direction === head.direction &&
        m.sender_type === head.sender_type &&
        (m.user?.id ?? null) === (head.user?.id ?? null) &&
        Math.abs(Date.parse(m.created_at ?? '') - Date.parse(head.created_at ?? '')) <= RUN_WINDOW_MS
    );
}

/** One row of the thread: a message (with its folded image run), a note, or 2+ notes in a row. */
export interface TimelineEntry {
    key: string;
    at: number;
    /** Cairo day key (yyyy-mm-dd), for the day rows. */
    day: string;
    iso: string | null;
    message?: Message;
    note?: Note;
    /** Image-only messages folded into `message`'s bubble (spec §1.5); `message` is the run's head. */
    group?: Message[];
    /** Two or more notes in a row on one day (no message between them), `note` first (§1.2). */
    notes?: Note[];
    /** First bubble of a sender run (same sender, gaps ≤ 5 min): gets the tail / avatar. */
    runStart: boolean;
}

type Item = { at: number; message?: Message; note?: Note };

const stampOf = (iso: string | null | undefined): number => (iso ? Date.parse(iso) : Date.now());

function itemsOf(messages: Message[], notes: Note[]): Item[] {
    // Messages first, then notes, then a stable sort: ties keep that order (as before 6c).
    const items: Item[] = [
        ...messages.map((message) => ({ at: stampOf(message.created_at), message })),
        ...notes.map((note) => ({ at: stampOf(note.created_at), note })),
    ];
    return items.sort((a, b) => a.at - b.at);
}

function entryItems(entry: TimelineEntry): Item[] {
    if (entry.message) {
        return [{ at: entry.at, message: entry.message }, ...(entry.group ?? []).map((m) => ({ at: stampOf(m.created_at), message: m }))];
    }
    return (entry.notes ?? (entry.note ? [entry.note] : [])).map((note) => ({ at: stampOf(note.created_at), note }));
}

function senderKey(entry: TimelineEntry): string {
    if (entry.note) return `note:${entry.note.user?.id ?? '_'}`;
    const m = entry.message;
    if (!m) return 'unknown';
    if (m.sender_type === 'system') return 'system';
    return `${m.direction}:${m.sender_type}:${m.user?.id ?? '_'}`;
}

// A folded image run's own `.at` is its FIRST image's time; the gap to the next bubble is
// measured from the run's LAST message.
function endAt(entry: TimelineEntry): number {
    const last = entry.group?.[entry.group.length - 1];
    return last ? stampOf(last.created_at) : entry.at;
}

function lastAt(entry: TimelineEntry): number {
    const items = entryItems(entry);
    return items.reduce((max, item) => Math.max(max, item.at), entry.at);
}

function isRunStart(previous: TimelineEntry | undefined, entry: TimelineEntry): boolean {
    if (!previous) return true;
    if (senderKey(previous) !== senderKey(entry)) return true;
    return entry.at - endAt(previous) > FIVE_MINUTES;
}

/** Would `item` fold into `last` (an image run or a note group) in a full build? */
function joins(last: TimelineEntry | undefined, item: Item): boolean {
    if (!last) return false;
    if (item.message && last.message) return foldsIntoRun(last.message, item.message);
    if (item.note && last.note) return last.day === cairoDayKey(item.note.created_at);
    return false;
}

/** Group sorted items into entries; `before` is the entry just above them (for runStart only). */
function group(items: Item[], before?: TimelineEntry): TimelineEntry[] {
    const out: TimelineEntry[] = [];
    for (const item of items) {
        const last = out[out.length - 1];
        if (last && joins(last, item)) {
            if (item.message) last.group = [...(last.group ?? []), item.message];
            else if (item.note) last.notes = [...(last.notes ?? [last.note as Note]), item.note];
            continue;
        }
        const source = item.message ?? item.note;
        const iso = source?.created_at ?? null;
        out.push({
            key: item.message ? `m-${item.message.client_key ?? item.message.id}` : `n-${item.note?.id}`,
            at: item.at,
            day: cairoDayKey(iso),
            iso,
            message: item.message,
            note: item.note,
            runStart: false,
        });
    }
    let previous = before;
    for (const entry of out) {
        entry.runStart = isRunStart(previous, entry);
        previous = entry;
    }
    return out;
}

interface Built {
    messages: Message[];
    notes: Note[];
}

/** What each returned timeline was built from, so the next merge can find the delta. */
const builtFrom = new WeakMap<TimelineEntry[], Built>();

function remember(entries: TimelineEntry[], messages: Message[], notes: Note[]): TimelineEntry[] {
    builtFrom.set(entries, { messages: messages.slice(), notes: notes.slice() });
    return entries;
}

/** Full build: every message and note, sorted, grouped, run starts marked. */
export function buildTimeline(messages: Message[], notes: Note[]): TimelineEntry[] {
    return remember(group(itemsOf(messages, notes)), messages, notes);
}

const sameNote = (a: Note, b: Note) => a.id === b.id && a.body === b.body;

/** Notes are newest-first: new ones arrive at the front. Returns the added notes, or null for any other change. */
function addedNotes(before: Note[], after: Note[]): Note[] | null {
    const extra = after.length - before.length;
    if (extra < 0) return null;
    for (let i = 0; i < before.length; i++) if (!sameNote(before[i], after[i + extra])) return null;
    return after.slice(0, extra);
}

function commonPrefix(a: Message[], b: Message[]): number {
    const n = Math.min(a.length, b.length);
    let i = 0;
    while (i < n && a[i] === b[i]) i++;
    return i;
}

/**
 * The thread's rows, rebuilt only where something changed (spec §1.3).
 *
 * - Change at the tail (new / replaced messages among the newest, new notes): the rows from the
 *   first one holding an item at or after the earliest change, plus one row above it (a new
 *   image may join its run), are regrouped; every row above is reused as is.
 * - Older page prepended (the old messages are an unchanged suffix, notes unchanged): the new
 *   items plus the first old row(s) they can fold into are regrouped; every row below is reused
 *   (only the first reused row's `runStart` is recomputed).
 * - Anything else (a message changed in the middle, a note removed or edited): full build.
 *
 * Messages are compared by identity, notes by id + body. The result is pure: the same input
 * gives the same rows as `buildTimeline`.
 *
 * @example
 *   let rows = buildTimeline(messages, notes);          // 400 rows
 *   messages.push(incoming);                            // a new customer message
 *   rows = mergeTimeline(rows, messages, notes);        // 399 reused + 1 regrouped row (or 2)
 *   messages.unshift(...olderPage);                     // 50 older messages
 *   rows = mergeTimeline(rows, messages, notes);        // ~50 new rows + the reused 400
 */
export function mergeTimeline(prev: TimelineEntry[], messages: Message[], notes: Note[]): TimelineEntry[] {
    const built = builtFrom.get(prev);
    if (!built || !prev.length) return buildTimeline(messages, notes);

    const newNotes = addedNotes(built.notes, notes);
    if (newNotes === null) return buildTimeline(messages, notes);

    const old = built.messages;
    const p = commonPrefix(old, messages);
    if (p === old.length && p === messages.length && !newNotes.length) return prev;

    // ---- prepend: old messages are an untouched suffix ----
    const extra = messages.length - old.length;
    if (p === 0 && extra > 0 && !newNotes.length && old.length > 0 && messages[extra] === old[0] && old.every((m, i) => messages[i + extra] === m)) {
        const added = itemsOf(messages.slice(0, extra), []);
        const until = added.reduce((max, item) => Math.max(max, item.at), -Infinity);
        let cut = 0;
        while (cut < prev.length && prev[cut].at <= until) cut++;
        cut = Math.min(prev.length, cut + 1); // one more row: an older image may lead its run now
        let window = [...added, ...prev.slice(0, cut).flatMap(entryItems)].sort((a, b) => a.at - b.at);
        let head = group(window);
        while (cut < prev.length && joins(head[head.length - 1], entryItems(prev[cut])[0])) {
            window = [...window, ...entryItems(prev[cut])];
            cut++;
            head = group(window);
        }
        const rest = prev.slice(cut);
        if (rest.length) {
            const runStart = isRunStart(head[head.length - 1], rest[0]);
            if (runStart !== rest[0].runStart) rest[0] = { ...rest[0], runStart };
        }
        return remember(head.concat(rest), messages, notes);
    }

    // ---- tail: everything before the earliest change is reused ----
    const removed = new Set(old.slice(p));
    const inserted = messages.slice(p);
    const changedAt = [
        ...old.slice(p).map((m) => stampOf(m.created_at)),
        ...inserted.map((m) => stampOf(m.created_at)),
        ...newNotes.map((n) => stampOf(n.created_at)),
    ];
    if (!changedAt.length) return prev;
    // A change deep in the thread is not worth the bookkeeping: rebuild.
    if (old.length - p > 50) return buildTimeline(messages, notes);
    const from = Math.min(...changedAt);

    let keep = prev.length;
    while (keep > 0 && lastAt(prev[keep - 1]) >= from) keep--;
    keep = Math.max(0, keep - 1); // one more row: a new image may join its run, a note its group

    const reused = prev.slice(0, keep);
    const kept = prev
        .slice(keep)
        .flatMap(entryItems)
        .filter((item) => !item.message || !removed.has(item.message));
    // Same tie order as a full build: messages (array order), then notes (array order).
    const tailMessages = kept.filter((i) => i.message).concat(inserted.map((message) => ({ at: stampOf(message.created_at), message })));
    const tailNotes = kept.filter((i) => i.note).concat(newNotes.map((note) => ({ at: stampOf(note.created_at), note })));
    const order = new Map<Message, number>();
    messages.forEach((m, i) => order.set(m, i));
    tailMessages.sort((a, b) => (order.get(a.message as Message) ?? 0) - (order.get(b.message as Message) ?? 0));
    const noteOrder = new Map<number, number>();
    notes.forEach((n, i) => noteOrder.set(n.id, i));
    tailNotes.sort((a, b) => (noteOrder.get(a.note?.id ?? 0) ?? 0) - (noteOrder.get(b.note?.id ?? 0) ?? 0));
    const window = [...tailMessages, ...tailNotes].sort((a, b) => a.at - b.at);

    return remember(reused.concat(group(window, reused[reused.length - 1])), messages, notes);
}
