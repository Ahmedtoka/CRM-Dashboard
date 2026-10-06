import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/** Final review C7: on a phone the status chips scroll under a fade with end padding, never cut flush by the sort pill. */
const list = Object.values(import.meta.glob('../ConversationList.vue', { query: '?raw', import: 'default', eager: true }) as Record<string, string>)[0];
const css = readFileSync(resolve(process.cwd(), 'resources/css/app.css'), 'utf8');

describe('inbox chip row', () => {
    it('keeps end padding and an inline-end fade on the scrolling tab list', () => {
        const row = list.match(/class="([^"]*)"\s*\n\s*data-chip-row/);
        expect(row).not.toBeNull();
        expect(row![1].split(' ')).toEqual(expect.arrayContaining(['fade-inline-end', 'pe-6', 'overflow-x-auto']));
    });

    it('defines the fade for both directions', () => {
        expect(css).toContain('.fade-inline-end');
        expect(css).toContain("[dir='rtl'] .fade-inline-end");
    });
});
