import ar from '@/i18n/ar';
import en from '@/i18n/en';
import { describe, expect, it } from 'vitest';
// The PHP enum is the source of truth for the reasons the funnel and the alert rules report.
import outcomeSource from '../../../../app/Inbox/Outcomes/Outcome.php?raw';

const cases = [...outcomeSource.matchAll(/case\s+\w+\s*=\s*'([a-z_]+)';/g)].map((m) => m[1]);

describe('Outcome strings', () => {
    it('reads the enum cases (every lost reason among them)', () => {
        expect(cases).toEqual(expect.arrayContaining(['price', 'size_out', 'shipping', 'no_answer', 'browsing', 'other']));
    });

    it.each(['ar', 'en'] as const)('every Outcome case has outcomes.<key> in %s', (locale) => {
        const dict = (locale === 'ar' ? ar : en).outcomes as Record<string, unknown>;
        for (const key of cases) expect(typeof dict[key], `${locale}: outcomes.${key}`).toBe('string');
    });
});
