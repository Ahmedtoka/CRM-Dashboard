import { mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: { visit: (...a: unknown[]) => visit(...a), post: vi.fn() } }));

import AlertCard from '@/components/ads/AlertCard.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import type { AlertCardData, AlertReason } from '@/types/ads';

beforeAll(() => setCurrentLocale('ar'));
beforeEach(() => visit.mockClear());

const ad = { id: 11, external_id: '2385', name: 'Eid Abaya V2', thumbnail_url: null, account_id: 3, account: 'LV-Main 2', buyer: 'Bakinam', can_write: true };
const reason = (over: Partial<AlertReason> = {}): AlertReason => ({
    alert_id: 5,
    rule_id: 'all.spend_no_result',
    severity: 'high',
    action: 'stop',
    sentence_key: 'spend_no_result',
    params: { spend: 1840, k: 3, days: 5, result: 'chat', cap: 1500 },
    evidence: {},
    first_fired_at: null,
    seen: false,
    state: 'open',
    snoozed_until: null,
    closed_at: null,
    closed_by: null,
    resolved_reason: null,
    dismiss_reason: null,
    can_dismiss: true,
    ...over,
});
const card = (over: Partial<AlertCardData> = {}): AlertCardData => ({
    key: 'ad:11',
    kind: 'ad',
    severity: 'high',
    money_at_risk_per_day: 410,
    first_fired_at: null,
    ad,
    product: null,
    account: { id: 3, name: 'LV-Main 2' },
    ads: [],
    reasons: [reason()],
    primary: { verb: 'stop', action: 'stop', alert_id: 5, href: null },
    alert_ids: [5],
    ...over,
});

describe('AlertCard', () => {
    it('renders the sentence and emits stop from the one primary button', async () => {
        const w = mount(AlertCard, { props: { card: card() } });
        expect(w.text()).toContain('ومفيش ولا محادثة');
        await w.get('[data-test="primary"]').trigger('click');
        expect(w.emitted('stop')?.[0]?.[0]).toBe(5);
        expect((w.emitted('stop')?.[0]?.[1] as { id: number }).id).toBe(11);
    });

    it('stacks two reasons on one card', () => {
        const reasons = [reason(), reason({ alert_id: 6, rule_id: 'all.price_mismatch', severity: 'medium', sentence_key: 'price_mismatch', params: { caption_price: 950, site_price: 1100 } })];
        const w = mount(AlertCard, { props: { card: card({ reasons, alert_ids: [5, 6] }) } });
        expect(w.findAll('li')).toHaveLength(2);
    });

    it('snoozes for three days from the later menu', async () => {
        const w = mount(AlertCard, { props: { card: card() } });
        await w.get('[data-test="later"]').trigger('click');
        await w.get('[data-test="later-3d"]').trigger('click');
        expect(w.emitted('snooze')?.[0]).toEqual([[5], '3d']);
    });

    it('disagrees with a one-tap reason chip', async () => {
        const w = mount(AlertCard, { props: { card: card() } });
        await w.get('[data-test="disagree"]').trigger('click');
        await w.get('[data-test="reason-wrong_numbers"]').trigger('click');
        await w.get('[data-test="disagree-send"]').trigger('click');
        expect(w.emitted('dismiss')?.[0]).toEqual([[5], 'wrong_numbers', '']);
    });

    it('hides disagree when a reason may only be dismissed by Ads authority, and visits href verbs', async () => {
        const reasons = [reason({ can_dismiss: false, action: 'check_stock' })];
        const w = mount(AlertCard, { props: { card: card({ reasons, primary: { verb: 'open_stock', action: 'check_stock', alert_id: 5, href: '/ads/stock' } }) } });
        expect(w.find('[data-test="disagree"]').exists()).toBe(false);
        await w.get('[data-test="primary"]').trigger('click');
        expect(visit).toHaveBeenCalledWith('/ads/stock');
    });

    it('opens the settings for a manager and asks a buyer to fetch one', async () => {
        const reasons = [reason({ action: 'open_settings', rule_id: 'sales.below_breakeven', sentence_key: 'breakeven_unprofitable', params: { account: 'LV' } })];
        const base = { kind: 'account' as const, ad: null, reasons };
        const manager = mount(AlertCard, { props: { card: card({ ...base, primary: { verb: 'open_settings', action: 'open_settings', alert_id: 5, href: '/ads/setup/rules' } }) } });
        await manager.get('[data-test="primary"]').trigger('click');
        expect(visit).toHaveBeenCalledWith('/ads/setup/rules');
        expect(manager.find('[data-test="ask-manager"]').exists()).toBe(false);

        const buyer = mount(AlertCard, { props: { card: card({ ...base, primary: { verb: 'open_settings', action: 'open_settings', alert_id: 5, href: null } }) } });
        expect(buyer.find('[data-test="primary"]').exists()).toBe(false);
        expect(buyer.get('[data-test="ask-manager"]').text()).toContain('اطلب من المدير يراجع الإعدادات');
        expect(buyer.text()).toContain('راجع الإعدادات');
    });

    it('lists the ads of an out-of-stock product with one Stop each', async () => {
        const w = mount(AlertCard, {
            props: {
                card: card({
                    kind: 'product',
                    ad: null,
                    product: { id: 9, title: 'Abaya' },
                    reasons: [reason({ rule_id: 'all.out_of_stock', sentence_key: 'out_of_stock', params: { product: 'Abaya' }, severity: 'critical' })],
                    ads: [
                        { ...ad, alert_id: 5, action: 'stop' },
                        { ...ad, id: 12, name: 'Other', alert_id: 7, action: 'stop' },
                    ],
                    alert_ids: [5, 7],
                }),
            },
        });
        expect(w.find('[data-test="primary"]').exists()).toBe(false);
        await w.get('[data-test="stop-12"]').trigger('click');
        expect(w.emitted('stop')?.[0]?.[0]).toBe(7);
    });

    it('shows how a closed card closed and no actions', () => {
        const reasons = [reason({ state: 'dismissed', dismiss_reason: 'wrong_numbers', closed_by: 'Bakinam', closed_at: '2026-10-05T10:00:00+03:00' })];
        const w = mount(AlertCard, { props: { card: card({ reasons }), mode: 'closed' } });
        expect(w.get('[data-test="state"]').text()).toContain('الأرقام غلط');
        expect(w.find('[data-test="primary"]').exists()).toBe(false);
        expect(w.find('[data-test="later"]').exists()).toBe(false);
    });

    it('shows the collapsed line after an action', () => {
        const w = mount(AlertCard, { props: { card: card(), actedLine: 'اتبعت إيقاف «Eid Abaya V2»' } });
        expect(w.text()).toContain('اتبعت إيقاف');
        expect(w.find('[data-test="primary"]').exists()).toBe(false);
    });
});
