import { adsNavChildren } from '@/lib/adsNav';
import { describe, expect, it } from 'vitest';

const t = (k: string) => k;

describe('adsNavChildren', () => {
    it('gives managers six items with the decisions badge and setup', () => {
        const items = adsNavChildren('admin', t, { decisions: 4, library: 2 }, '?range=last30&accounts=3&health=tired');
        expect(items.map((i) => i.href)).toEqual(['/ads', '/ads/decisions', '/ads/explorer', '/ads/numbers', '/ads/materials', '/ads/setup']);
        expect(items[1].badge).toBe(4);
        expect(items[4].badge).toBe(2);
        expect(items[0].query).toBe('?range=last30&accounts=3');
        expect(items[4].query).toBeUndefined();
        expect(items[5].match).toEqual(['/ads/accounts', '/ads/sync', '/ads/setup']);
    });

    it('gives media buyers five items (no setup)', () => {
        const items = adsNavChildren('media_buyer', t, { decisions: 0, library: 0 }, '');
        expect(items.map((i) => i.title)).toEqual(['nav.ads_today', 'nav.ads_decisions', 'nav.ads_explorer', 'nav.ads_numbers', 'nav.ads_library']);
        expect(items[1].badge).toBe(0);
    });

    it('shows no decisions badge while the count is not cached', () => {
        expect(adsNavChildren('supervisor', t, { decisions: null, library: 0 }, '')[1].badge).toBeNull();
    });
});

describe('adsNavChildren library match (final review C1)', () => {
    it('keeps «المكتبة» active on the launches page its badge counts', () => {
        expect(adsNavChildren('media_buyer', t, { decisions: 0, library: 2 }, '')[4].match).toContain('/ads/launches');
    });
});
