import { activePreset, buildHref, carryQuery, nextSort, parseSort, presetQuery, readQuery, withParam } from '@/lib/adsFilters';
import { describe, expect, it } from 'vitest';

describe('adsFilters', () => {
    it('reads accounts from both spellings', () => {
        expect(readQuery('?accounts[]=3&accounts[]=4&range=last7')).toEqual({ accounts: '3,4', range: 'last7' });
        expect(readQuery('?accounts=5,6')).toEqual({ accounts: '5,6' });
    });

    it('applies a preset: keeps shared params, drops list filters, sets its own', () => {
        expect(presetQuery({ range: 'last30', accounts: '3', health: 'tired', q: 'x', page: '2', ad: '9' }, 'no_result')).toEqual({
            range: 'last30', accounts: '3', ad: '9', status: 'running', health: 'no_result', sort: '-spend',
        });
        expect(presetQuery({ buyer: '4' }, 'mine')).toEqual({ buyer: 'me' });
    });

    it('detects the active preset and marks it modified when another list filter changes', () => {
        expect(activePreset({ health: 'tired', sort: '-spend' })).toEqual({ key: 'tired', modified: false });
        expect(activePreset({ health: 'tired', sort: '-spend', objective: 'messages' })).toEqual({ key: 'tired', modified: true });
        expect(activePreset({ q: 'abaya' })).toBeNull();
    });

    it('cycles a column desc → asc → desc and starts another column desc', () => {
        expect(parseSort('-spend')).toEqual({ key: 'spend', dir: 'desc' });
        expect(nextSort('-spend', 'spend')).toBe('spend');
        expect(nextSort('spend', 'spend')).toBe('-spend');
        expect(nextSort('-spend', 'roas')).toBe('-roas');
    });

    it('carries only the shared params to the other Ads pages', () => {
        expect(carryQuery({ range: 'last7', accounts: '3', health: 'tired', ad: '9' })).toEqual({ range: 'last7', accounts: '3' });
    });

    it('drops default values and resets the page when a filter changes', () => {
        expect(withParam({ status: 'paused', page: '3' }, 'status', 'running', { status: 'running' })).toEqual({});
        expect(withParam({ page: '3' }, 'health', 'tired')).toEqual({ health: 'tired' });
        expect(withParam({ health: 'tired' }, 'health', null)).toEqual({});
    });

    it('builds hrefs without empty params', () => {
        expect(buildHref('/ads/explorer', { view: 'cards', q: '' })).toBe('/ads/explorer?view=cards');
        expect(buildHref('/ads/explorer', {})).toBe('/ads/explorer');
    });
});
