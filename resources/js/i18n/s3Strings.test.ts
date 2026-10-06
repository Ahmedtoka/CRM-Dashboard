import { translate } from '@/i18n';
import ar from '@/i18n/ar';
import en from '@/i18n/en';
import { describe, expect, it } from 'vitest';

const KEYS = [
    'outcomes.title', 'outcomes.ordered', 'outcomes.price', 'outcomes.size_out', 'outcomes.shipping', 'outcomes.no_answer',
    'outcomes.browsing', 'outcomes.service', 'outcomes.other', 'outcomes.unknown', 'outcomes.auto', 'outcomes.locked_hint',
    'outcomes.pick_first', 'outcomes.note_placeholder', 'outcomes.digits_hint', 'outcomes.more',
    'thread.header.resolve_menu', 'thread.header.resolve_confirm',
    'inbox.waiting_age', 'inbox.sort.label', 'inbox.sort.oldest_waiting',
    'inbox.bot_summary.title', 'inbox.bot_summary.reason', 'inbox.bot_summary.category', 'inbox.bot_summary.topic',
    'inbox.bot_summary.order', 'inbox.bot_summary.products', 'inbox.bot_summary.sizes', 'inbox.bot_summary.colors',
    'inbox.bot_summary.governorate', 'inbox.bot_summary.last_message',
    'inbox.ad.title', 'inbox.ad.campaign', 'inbox.ad.adset', 'inbox.ad.open', 'inbox.ad.more', 'inbox.ad.history',
    'orders.columns.ad', 'orders.ad_source.direct', 'customer.ads',
    'order.insert_status', 'order.no_orders_to_insert', 'order.prefilled', 'order.prefill_clear', 'order.prefill_missing', 'order.prefill_size_out', 'order.prefill_color_out', 'order.prefill_stock_out',
    'shortcuts.insert_status',
    'ads.funnel.title', 'ads.funnel.chats', 'ads.funnel.to_agent', 'ads.funnel.orders', 'ads.funnel.delivered',
    'ads.funnel.returned', 'ads.funnel.why', 'ads.funnel.no_reasons', 'ads.funnel.empty', 'ads.funnel.multi_touch',
];

/** translate() falls back to Arabic, so the English file is checked directly. */
function raw(dict: unknown, key: string): unknown {
    return key.split('.').reduce<unknown>((node, part) => (node && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined), dict);
}

describe('S3 strings', () => {
    it.each(KEYS)('has %s in ar and en', (key) => {
        expect(translate('ar', key)).not.toBe(key);
        expect(typeof raw(en, key)).toBe('string');
        expect(raw(en, key)).not.toBe(raw(ar, key));
    });

    it('keeps the two files free of emoji', () => {
        const emoji = /\p{Extended_Pictographic}/u;
        expect(emoji.test(JSON.stringify(ar))).toBe(false);
        expect(emoji.test(JSON.stringify(en))).toBe(false);
    });
});
