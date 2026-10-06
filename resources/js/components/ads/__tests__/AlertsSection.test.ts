import { flushPromises, mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

const { reload, post } = vi.hoisted(() => ({ reload: vi.fn(), post: vi.fn(() => Promise.resolve({ data: { ok: true } })) }));
vi.mock('@inertiajs/vue3', () => ({
    router: { reload: (...a: unknown[]) => reload(...a), visit: vi.fn(), post: vi.fn(), get: vi.fn() },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/composables/useApi', () => ({ useApi: () => ({ post }), apiErrorMessage: (_e: unknown, f: string) => f }));

import AlertsSection from '@/components/ads/AlertsSection.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import type { AlertCardData, AlertSeverity, AlertsMeta } from '@/types/ads';

beforeAll(() => setCurrentLocale('ar'));

const reason = (id: number, severity: AlertSeverity) => ({
    alert_id: id,
    rule_id: 'all.spend_no_result',
    severity,
    action: 'stop',
    sentence_key: 'spend_no_result',
    params: { spend: 1000, k: 3, days: 5, result: 'purchase', cap: 1500 },
    evidence: {},
    first_fired_at: null,
    seen: false,
    state: 'open' as const,
    snoozed_until: null,
    closed_at: null,
    closed_by: null,
    resolved_reason: null,
    dismiss_reason: null,
    can_dismiss: true,
});
const card = (id: number, severity: AlertSeverity = 'high'): AlertCardData => ({
    key: `ad:${id}`,
    kind: 'ad',
    severity,
    money_at_risk_per_day: 100 * id,
    first_fired_at: null,
    ad: { id, external_id: String(id), name: `Ad ${id}`, thumbnail_url: null, account_id: 1, account: 'LV', buyer: null, can_write: true },
    product: null,
    account: null,
    ads: [],
    reasons: [reason(id, severity)],
    primary: { verb: severity === 'info' ? 'why' : 'stop', action: 'stop', alert_id: id, href: null },
    alert_ids: [id],
});
const meta: AlertsMeta = {
    tab: 'open',
    counts: { open: 2, later: 0, closed: 0 },
    hidden_by_cap: 3,
    buyer_cap: 7,
    shadow: true,
    can_toggle: true,
    can_open_settings: true,
};
const DialogStub = {
    name: 'WriteActionDialog',
    props: ['open', 'sourceRef', 'source', 'to'],
    template: '<div data-test="dialog" :data-open="open" />',
};
const stubs = { WriteActionDialog: DialogStub };

beforeEach(() => {
    reload.mockClear();
    post.mockClear();
});

describe('AlertsSection', () => {
    it('shows the shadow ribbon with the Setup link and the cap note', () => {
        const w = mount(AlertsSection, { props: { alerts: [card(1), card(2)], meta }, global: { stubs } });
        expect(w.get('[data-test="shadow-ribbon"]').text()).toContain('وضع تجربة');
        expect(w.find('[data-test="shadow-toggle"]').attributes('href')).toBe('/ads/setup/rules');
        expect(w.find('[data-test="cap-note"]').exists()).toBe(true);
    });

    it('hides the Setup link from people who cannot open the rules page', () => {
        const w = mount(AlertsSection, { props: { alerts: [], meta: { ...meta, can_toggle: true, can_open_settings: false } }, global: { stubs } });
        expect(w.find('[data-test="shadow-ribbon"]').exists()).toBe(true);
        expect(w.find('[data-test="shadow-toggle"]').exists()).toBe(false);
    });

    it('groups by severity and folds the opportunities', async () => {
        const w = mount(AlertsSection, { props: { alerts: [card(1, 'info'), card(2, 'critical')], meta }, global: { stubs } });
        expect(w.findAll('[data-test^="group-"]').map((g) => g.attributes('data-test'))).toEqual(['group-critical', 'group-info']);
        expect(w.find('[data-card="ad:1"]').exists()).toBe(false);
        await w.get('[data-test="info-toggle"]').trigger('click');
        expect(w.find('[data-card="ad:1"]').exists()).toBe(true);
    });

    it('steps through cards with J and K in review mode and marks them seen', async () => {
        const w = mount(AlertsSection, { props: { alerts: [card(1), card(2)], meta }, global: { stubs }, attachTo: document.body });
        await w.get('[data-test="review"]').trigger('click');
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'j' }));
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k' }));
        await flushPromises();
        expect(w.emitted('open-ad')?.map((e) => e[0])).toEqual([1, 2, 1]);
        expect(post).toHaveBeenCalledWith('/ads/alerts/seen', { ids: [2] }, { silent: true });
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await flushPromises();
        expect(w.find('[data-test="review-hint"]').exists()).toBe(false);
        w.unmount();
    });

    it('snoozes a card and reloads the section', async () => {
        const w = mount(AlertsSection, { props: { alerts: [card(1)], meta }, global: { stubs } });
        await w.get('[data-test="later"]').trigger('click');
        await w.get('[data-test="later-tomorrow"]').trigger('click');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/ads/alerts/snooze', { ids: [1], until: 'tomorrow' });
        expect(reload).toHaveBeenCalledWith({ only: ['alerts', 'alertsMeta', 'counts'] });
    });

    it('opens the server-diff dialog for Stop with the alert as source, then collapses the card', async () => {
        const w = mount(AlertsSection, { props: { alerts: [card(1)], meta }, global: { stubs } });
        await w.get('[data-test="primary"]').trigger('click');
        const dialog = w.getComponent(DialogStub);
        expect(dialog.props('open')).toBe(true);
        expect(dialog.props('source')).toBe('alert');
        expect(dialog.props('sourceRef')).toBe('1');
        expect(dialog.props('to')).toBe('paused');
        dialog.vm.$emit('done', 'paused');
        await flushPromises();
        expect(w.get('[data-test="acted"]').text()).toContain('اتبعت إيقاف');
        expect(reload).toHaveBeenCalled();
    });

    it('shows the empty text on the later tab', () => {
        const w = mount(AlertsSection, { props: { alerts: [], meta: { ...meta, tab: 'later' }, mode: 'later' }, global: { stubs } });
        expect(w.text()).toContain('مفيش حاجة مأجّلة');
        expect(w.find('[data-test="shadow-ribbon"]').exists()).toBe(false);
    });
});
