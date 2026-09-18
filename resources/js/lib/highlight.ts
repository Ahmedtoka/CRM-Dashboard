/** Splits `text` into runs so the command palette can highlight the matched query without `v-html`. */
export function highlightParts(text: string, query: string): Array<{ text: string; match: boolean }> {
    const q = query.trim();
    if (!q) return [{ text, match: false }];
    const escaped = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return text.split(new RegExp(`(${escaped})`, 'giu')).filter(Boolean).map((part) => ({ text: part, match: part.toLowerCase() === q.toLowerCase() }));
}
