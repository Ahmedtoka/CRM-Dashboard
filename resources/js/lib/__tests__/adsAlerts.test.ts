import { alertSentence, groupBySeverity } from '@/lib/adsAlerts';
import { describe, expect, it } from 'vitest';

describe('adsAlerts', () => {
    it('writes the catalogue sentence in Egyptian Arabic with translated words', () => {
        const text = alertSentence({ sentence_key: 'spend_no_result', params: { spend: 1200, k: 4, days: 8, result: 'purchase', cap: 1500 } }, 'ar');
        expect(text).toContain('ومفيش ولا عملية شراء');
        expect(text).toContain('التكلفة المستهدفة');
        expect(text).not.toContain('{');
    });

    it('adds the default break-even note and the replacement note', () => {
        const text = alertSentence(
            {
                sentence_key: 'below_breakeven',
                params: { roas: 1, roas_meta: 1, roas_crm: 0, floor: 2.5, floor_default: true, no_alternative: true },
            },
            'ar',
        );
        expect(text).toContain('نقطة التعادل افتراضية');
        expect(text).toContain('ضيف بديل الأول');
    });

    it('adds the slow-inbox note when the engine flags it', () => {
        const ar = alertSentence({ sentence_key: 'chats_no_orders', params: { chats: 30, days: 7, k: 3, spend: 900, inbox_slow: true } }, 'ar');
        expect(ar).toContain('الإنبوكس كان بطيء');
        const en = alertSentence({ sentence_key: 'chats_no_orders', params: { chats: 30, days: 7, k: 3, spend: 900, inbox_slow: true } }, 'en');
        expect(en).toContain('inbox was slow');
    });

    it('has the unprofitable-account sentence', () => {
        expect(alertSentence({ sentence_key: 'breakeven_unprofitable', params: { account: 'LV' } }, 'ar')).toContain('راجع الإعدادات');
        expect(alertSentence({ sentence_key: 'breakeven_unprofitable', params: { account: 'LV' } }, 'en')).toContain('settings');
    });

    it('translates the product status and keeps an unknown status as is', () => {
        expect(alertSentence({ sentence_key: 'product_unavailable', params: { product: 'Abaya', status: 'draft' } }, 'ar')).toContain('مسودة');
        expect(alertSentence({ sentence_key: 'product_unavailable', params: { product: 'Abaya', status: 'draft' } }, 'en')).toContain('draft');
        expect(alertSentence({ sentence_key: 'product_unavailable', params: { product: 'Abaya', status: 'pending' } }, 'ar')).toContain('pending');
    });

    it('has a sentence for every rule key in both languages', () => {
        const keys = [
            'out_of_stock',
            'out_of_stock_msg',
            'spend_spike_today',
            'spend_no_result',
            'spend_no_result_learning',
            'spend_no_result_cap',
            'below_breakeven',
            'breakeven_unprofitable',
            'chats_no_orders',
            'price_mismatch',
            'sizes_broken',
            'product_unavailable',
            'inbox_slow_for_ads',
            'high_refusal',
            'scale_winner',
            'scale_winner_msg',
            'reactivate_restocked',
            'chat_size_out',
            'chat_price',
        ];
        for (const key of keys) {
            for (const locale of ['ar', 'en'] as const) {
                expect(alertSentence({ sentence_key: key, params: {} }, locale), `${locale}:${key}`).not.toContain('ads.alerts.rules');
            }
        }
    });

    it('groups cards by severity in order and drops empty groups', () => {
        const groups = groupBySeverity([{ severity: 'medium' as const }, { severity: 'critical' as const }, { severity: 'medium' as const }]);
        expect(groups.map((g) => [g.severity, g.cards.length])).toEqual([
            ['critical', 1],
            ['medium', 2],
        ]);
    });
});
