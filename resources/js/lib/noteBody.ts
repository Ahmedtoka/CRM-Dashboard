import type { Note, UserRef } from '@/types/crm';

export interface NotePart {
    text: string;
    mention: boolean;
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * A note body split into plain text and @mentions, for rendering as text spans (never HTML).
 * Only the note's own mentioned users count, resolved by id against the roster; the pattern is
 * built from their names, longest first, so «@Sara Ahmed» wins over «@Sara» at the same spot
 * and names of three words or with punctuation right after still match.
 *
 *     noteParts({ body: 'كلمي @زينب علي بكرة', mentions: [7] }, [{ id: 7, name: 'زينب علي' }])
 *     // [{ text: 'كلمي ', mention: false }, { text: '@زينب علي', mention: true }, { text: ' بكرة', mention: false }]
 */
export function noteParts(note: Pick<Note, 'body' | 'mentions'>, mentionable: Pick<UserRef, 'id' | 'name'>[]): NotePart[] {
    const body = note.body ?? '';
    const names = (note.mentions ?? []).map((id) => mentionable.find((u) => u.id === id)?.name).filter((name): name is string => !!name);
    if (!names.length) return [{ text: body, mention: false }];

    const pattern = new RegExp(
        `(@(?:${[...names]
            .sort((a, b) => b.length - a.length)
            .map(escapeRegExp)
            .join('|')}))`,
        'u',
    );
    const tokens = new Set(names.map((name) => `@${name}`));

    return body
        .split(pattern)
        .filter((part) => part !== '')
        .map((part) => ({ text: part, mention: tokens.has(part) }));
}

/** The first non-empty line of a note, for its collapsed one-line row. */
export function noteFirstLine(body: string | null | undefined): string {
    return (
        (body ?? '')
            .split(/\r?\n/)
            .find((line) => line.trim() !== '')
            ?.trim() ?? ''
    );
}
