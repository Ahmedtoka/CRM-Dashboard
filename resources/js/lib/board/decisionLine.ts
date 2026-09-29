/**
 * The router writes its decision lines as small HTML fragments (`<b>…</b>` and
 * `<span class="ok|no|hi">…</span>`, names escaped). The board never puts them in the page as
 * HTML: they are read into plain text segments with a tone, and anything else that looks like
 * a tag is dropped.
 */

export type DecisionTone = 'plain' | 'strong' | 'ok' | 'no' | 'hi';

export interface DecisionSegment {
    text: string;
    tone: DecisionTone;
}

const MARKED = /<b>([\s\S]*?)<\/b>|<span class="(ok|no|hi)">([\s\S]*?)<\/span>/g;

const ENTITIES: Record<string, string> = { '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#039;': "'", '&#39;': "'", '&amp;': '&' };

function plain(text: string): string {
    return text.replace(/<[^>]*>/g, '').replace(/&(?:lt|gt|quot|#0?39|amp);/g, (entity) => ENTITIES[entity] ?? entity);
}

export function parseDecisionLine(line: string): DecisionSegment[] {
    const segments: DecisionSegment[] = [];
    const push = (text: string, tone: DecisionTone) => {
        const clean = plain(text);
        if (clean !== '') segments.push({ text: clean, tone });
    };

    let last = 0;
    for (const match of String(line ?? '').matchAll(MARKED)) {
        const at = match.index ?? 0;
        push(line.slice(last, at), 'plain');
        if (match[1] !== undefined) push(match[1], 'strong');
        else push(match[3] ?? '', (match[2] as DecisionTone) ?? 'plain');
        last = at + match[0].length;
    }
    push(String(line ?? '').slice(last), 'plain');

    return segments;
}
