import { describe, expect, it } from 'vitest';

import source from './NavMain.vue?raw';

describe('NavMain badges', () => {
    it('renders exactly one badge after each child title', () => {
        const blocks = source.split('<span>{{ child.title }}</span>').slice(1);
        expect(blocks).toHaveLength(2);
        for (const block of blocks) {
            const beforeLinkEnd = block.slice(0, block.indexOf('</Link>'));
            expect(beforeLinkEnd.match(/\{\{ child\.badge \}\}/g) ?? []).toHaveLength(1);
        }
    });
});
