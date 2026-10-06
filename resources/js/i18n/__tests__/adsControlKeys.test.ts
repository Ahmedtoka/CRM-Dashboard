import ar from '@/i18n/ar';
import en from '@/i18n/en';
import { describe, expect, it } from 'vitest';

const flat = (o: unknown, p = ''): string[] =>
    Object.entries(o as Record<string, unknown>).flatMap(([k, v]) => (v && typeof v === 'object' ? flat(v, `${p}${k}.`) : [`${p}${k}`]));

describe('S2 strings', () => {
    it('has the same ads.control keys in ar and en', () => {
        expect(flat(ar.ads.control).sort()).toEqual(flat(en.ads.control).sort());
    });
    it('has the six nav labels', () => {
        for (const k of ['ads_today', 'ads_decisions', 'ads_explorer', 'ads_numbers', 'ads_library', 'ads_setup'] as const) {
            expect(ar.nav[k]).toBeTruthy();
            expect(en.nav[k]).toBeTruthy();
        }
    });
    it('contains no emoji', () => {
        const all = JSON.stringify(ar.ads.control) + JSON.stringify(en.ads.control);
        expect(/\p{Extended_Pictographic}/u.test(all)).toBe(false);
    });
});
