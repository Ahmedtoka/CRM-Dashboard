import ar from '@/i18n/ar';
import en from '@/i18n/en';
import { describe, expect, it } from 'vitest';

/** Every literal t('...') key of the S1 launch UI exists in both dictionaries (en never falls back to ar). */
const sources = {
    ...(import.meta.glob('../components/ads/launch/*.vue', { query: '?raw', import: 'default', eager: true }) as Record<string, string>),
    ...(import.meta.glob(['../pages/Ads/Launches.vue', '../pages/Ads/Approvals.vue', '../pages/Ads/Materials/Index.vue'], {
        query: '?raw',
        import: 'default',
        eager: true,
    }) as Record<string, string>),
};

function has(dict: unknown, key: string): boolean {
    let node: unknown = dict;
    for (const part of key.split('.')) {
        if (node === null || typeof node !== 'object' || !(part in (node as Record<string, unknown>))) return false;
        node = (node as Record<string, unknown>)[part];
    }

    return typeof node === 'string';
}

const keys = [...new Set(Object.values(sources).flatMap((src) => [...src.matchAll(/\bt\(\s*'([a-z0-9_.]+)'/g)].map((m) => m[1])))];

describe('S1 launch strings', () => {
    it('finds keys to check', () => {
        expect(keys.length).toBeGreaterThan(10);
    });

    it.each(keys)('%s exists in ar and en', (key) => {
        expect(has(ar, key), `ar: ${key}`).toBe(true);
        expect(has(en, key), `en: ${key}`).toBe(true);
    });
});
