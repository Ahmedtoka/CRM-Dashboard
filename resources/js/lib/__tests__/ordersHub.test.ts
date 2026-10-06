import { adsManagerUrl } from '@/lib/ads';
import { drawerRange } from '@/lib/ordersHub';
import { describe, expect, it } from 'vitest';

describe('adsManagerUrl', () => {
    it('opens the ad inside its account (act=, without the act_ prefix) when the account is known', () => {
        expect(adsManagerUrl({ platform: 'meta', external_id: '123', account_external_id: 'act_987' })).toBe(
            'https://www.facebook.com/adsmanager/manage/ads?act=987&selected_ad_ids=123',
        );
        expect(adsManagerUrl({ platform: 'meta', external_id: '123' })).toBe('https://www.facebook.com/adsmanager/manage/ads?selected_ad_ids=123');
        expect(adsManagerUrl({ platform: 'tiktok', external_id: '1', account_external_id: '2' })).toBeNull();
    });
});

describe('drawerRange', () => {
    it('keeps the page period and falls back to this month on a triage view', () => {
        expect(drawerRange({ from: '2026-10-01', to: '2026-10-05' })).toEqual({ from: '2026-10-01', to: '2026-10-05', platform: null, buyer: null });
        const r = drawerRange({ from: null, to: null });
        expect(r.from).toMatch(/^\d{4}-\d{2}-01$/);
        expect(r.to >= r.from).toBe(true);
    });
});
