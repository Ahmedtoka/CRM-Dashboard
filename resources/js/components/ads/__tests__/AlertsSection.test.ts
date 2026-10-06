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

    it('steps through cards with J and K in review mode and marks them seen once the presses settle (final fix 4)', async () => {
        vi.useFakeTimers();
        const w = mount(AlertsSection, { props: { alerts: [card(1), card(2), card(3)], meta }, global: { stubs }, attachTo: document.body });
        await w.get('[data-test="review"]').trigger('click');
        vi.advanceTimersByTime(300);
        expect(w.emitted('open-ad')?.map((e) => e[0])).toEqual([1]);
        // Arabic layout: event.key is «ت», the physical key is J.
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ت', code: 'KeyJ' }));
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'j', code: 'KeyJ' }));
        await flushPromises();
        // The focus moves at once; the drawer and the seen post wait for the presses to settle.
        expect(document.activeElement?.getAttribute('data-card')).toBe('ad:3');
        expect(w.emitted('open-ad')).toHaveLength(1);
        vi.advanceTimersByTime(300);
        expect(w.emitted('open-ad')?.map((e) => e[0])).toEqual([1, 3]);
        expect(post).toHaveBeenCalledTimes(2);
        expect(post).toHaveBeenLastCalledWith('/ads/alerts/seen', { ids: [3] }, { silent: true });
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', code: 'KeyK' }));
        await flushPromises();
        expect(document.activeElement?.getAttribute('data-card')).toBe('ad:2');
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await flushPromises();
        expect(w.find('[data-test="review-hint"]').exists()).toBe(false);
        w.unmount();
        vi.useRealTimers();
    });

    it('ignores held keys, Ctrl+K and keys outside the review (final fix 4)', async () => {
        const w = mount(AlertsSection, { props: { alerts: [card(1), card(2)], meta }, global: { stubs }, attachTo: document.body });
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'j', code: 'KeyJ' }));
        await w.get('[data-test="review"]').trigger('click');
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'j', code: 'KeyJ', repeat: true }));
        const ctrlK = new KeyboardEvent('keydown', { key: 'k', code: 'KeyK', ctrlKey: true, cancelable: true });
        window.dispatchEvent(ctrlK);
        await flushPromises();
        expect(document.activeElement?.getAttribute('data-card')).toBe('ad:1');
        expect(w.find('[data-card="ad:1"]').attributes('aria-current')).toBe('true');
        w.unmount();
    });

    it('keeps the focus on an existing card when the list shrinks (final fix 4)', async () => {
        const w = mount(AlertsSection, { props: { alerts: [card(1), card(2)], meta }, global: { stubs }, attachTo: document.body });
        await w.get('[data-test="review"]').trigger('click');
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'j', code: 'KeyJ' }));
        await flushPromises();
        await w.setProps({ alerts: [card(1)] });
        await flushPromises();
        expect(w.find('[data-card="ad:1"]').attributes('aria-current')).toBe('true');
        w.unmount();
    });

    it('lists the review keys in the shortcuts registry (final fix 4)', async () => {
        const { useShortcutRegistry } = await import('@/composables/useShortcuts');
        const w = mount(AlertsSection, { props: { alerts: [card(1), card(2)], meta }, global: { stubs } });
        expect(useShortcutRegistry().list.value.filter((d) => d.group === 'decisions').map((d) => d.id)).toEqual(['decisions.next', 'decisions.prev', 'decisions.exit']);
        w.unmount();
    });

    it('never posts a snooze twice while the first is on the way (final fix 7)', async () => {
        let done: (v: { data: { ok: boolean } }) => void = () => undefined;
        post.mockImplementationOnce(() => new Promise<{ data: { ok: boolean } }>((r) => (done = r)));
        const w = mount(AlertsSection, { props: { alerts: [card(1)], meta }, global: { stubs } });
        await w.get('[data-test="later"]').trigger('click');
        await w.get('[data-test="later-tomorrow"]').trigger('click');
        expect(w.get('[data-test="later"]').attributes('disabled')).toBeDefined();
        w.getComponent({ name: 'AlertCard' }).vm.$emit('snooze', [1], 'tomorrow');
        await flushPromises();
        expect(post).toHaveBeenCalledTimes(1);
        done({ data: { ok: true } });
        await flushPromises();
        expect(w.get('[data-test="later"]').attributes('disabled')).toBeUndefined();
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

    it('final review C8: an Escape that closes the ad drawer keeps review mode on', async () => {
        const w = mount(AlertsSection, { props: { alerts: [card(1), card(2)], meta }, global: { stubs }, attachTo: document.body });
        await w.get('[data-test="review"]').trigger('click');
        // The drawer: an open dialog that closes itself on the document (as radix does), before the window hears the key.
        const sheet = document.createElement('div');
        sheet.setAttribute('role', 'dialog');
        sheet.setAttribute('data-state', 'open');
        document.body.appendChild(sheet);
        const closeSheet = (e: KeyboardEvent) => e.key === 'Escape' && sheet.remove();
        document.addEventListener('keydown', closeSheet);

        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();
        expect(sheet.isConnected).toBe(false);
        expect(w.find('[data-test="review-hint"]').exists()).toBe(true);

        // A second Escape, with no dialog left, exits the review.
        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();
        expect(w.find('[data-test="review-hint"]').exists()).toBe(false);
        document.removeEventListener('keydown', closeSheet);
        w.unmount();
    });
});
