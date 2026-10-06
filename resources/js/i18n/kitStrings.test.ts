import { translate } from '@/i18n';
import { describe, expect, it } from 'vitest';

const KEYS = [
    'ui.loading', 'ui.freshness', 'ui.progress', 'ui.retry', 'ui.load_failed', 'ui.breadcrumbs', 'ui.results',
    'table.sort_by', 'table.select_all', 'table.select_row', 'table.totals', 'filters.presets',
];

describe('S0 kit strings', () => {
    it('has no table density strings any more (F7)', () => {
        for (const key of ['table.density', 'table.density_comfortable', 'table.density_compact']) expect(translate('ar', key)).toBe(key);
    });

    it.each(KEYS)('%s exists in ar and en', (key) => {
        expect(translate('ar', key)).not.toBe(key);
        expect(translate('en', key)).not.toBe(key);
    });

    it('formats the progress line in the page digits', () => {
        expect(translate('ar', 'ui.progress', { n: 3, m: 10 })).toBe('٣ من ١٠');
        expect(translate('en', 'ui.progress', { n: 3, m: 10 })).toBe('3 of 10');
    });
});
