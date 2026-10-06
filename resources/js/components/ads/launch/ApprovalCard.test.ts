import ApprovalCard from '@/components/ads/launch/ApprovalCard.vue';
import type { CheckRow, LaunchRow } from '@/types/ads';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const check = (key: string, level: CheckRow['level']): CheckRow => ({ key, level, message_ar: key, message_en: key, details: {} });

function launch(over: Partial<LaunchRow> = {}): LaunchRow {
    return {
        id: '01J0000000000000000000TEST',
        state: 'awaiting_approval',
        hold_from: null,
        revision: 3,
        ads_count: 2,
        material: { id: 1, title: 'Silk reel', thumb_url: null },
        product: { id: 1, title: 'Silk abaya', image_url: null, prices: [450], inventory: 14 },
        account: { id: 1, name: 'LV Main', platform: 'meta' },
        campaign: { external_id: 'c1', name: 'LV | Abaya | Sales | Ali | 261004', status: 'ACTIVE', objective: 'OUTCOME_SALES' },
        adset: { id: 1, external_id: 's1', name: 'Broad | EG | Advantage+', status: 'ACTIVE' },
        identity: null,
        link: 'https://levoilestores.com/products/silk-abaya',
        url_tags: 'utm_content={{ad.id}}',
        file_ids: [1],
        files: [],
        captions: [{ headline: 'H', primary_text: 'T', cta: 'SHOP_NOW' }],
        original: null,
        people: { preparer: { id: 1, name: 'Sara' }, buyer: { id: 2, name: 'Ali' }, forwarder: { id: 3, name: 'Ali' }, decider: null },
        dates: {
            created_at: null,
            submitted_at: null,
            forwarded_at: null,
            awaiting_at: '2026-10-06T10:00:00Z',
            expires_at: '2026-10-13T10:00:00Z',
            decided_at: null,
            live_at: null,
            stopped_at: null,
        },
        decision: null,
        last_error: null,
        self_approved: false,
        checks: [check('stock', 'pass'), check('landing_http', 'warn')],
        checks_hash: 'a'.repeat(64),
        publications: [],
        history: [],
        money: { cap: 20000, currency: 'EGP', parent_status: 'ACTIVE', campaign_status: 'ACTIVE' },
        can: {
            edit: false,
            submit: false,
            send_back: false,
            forward: false,
            withdraw: false,
            retry: false,
            approve: true,
            return: true,
            reject: true,
            stop: false,
            retire: false,
        },
        ...over,
    };
}

const approveButton = (w: ReturnType<typeof mount>) => w.findAll('button').find((b) => b.text().includes('وافق وشغّل'))!;

describe('ApprovalCard', () => {
    it('keeps Approve disabled until every warning is ticked, then emits the acknowledged keys', async () => {
        const w = mount(ApprovalCard, { props: { launch: launch(), canApprove: true, writesOn: true, busy: false, isAdmin: false } });

        expect(approveButton(w).attributes('disabled')).toBeDefined();
        await w.find('input[type="checkbox"]').setValue(true);
        expect(approveButton(w).attributes('disabled')).toBeUndefined();
        await approveButton(w).trigger('click');
        expect(w.emitted('approve')?.[0]).toEqual([['landing_http']]);
    });

    it('blocks Approve on a blocking check, with writes off, or on hold', () => {
        const blocked = mount(ApprovalCard, {
            props: { launch: launch({ checks: [check('caption_price', 'block')] }), canApprove: true, writesOn: true, busy: false, isAdmin: false },
        });
        const off = mount(ApprovalCard, {
            props: { launch: launch({ checks: [] }), canApprove: true, writesOn: false, busy: false, isAdmin: false },
        });
        const held = mount(ApprovalCard, {
            props: {
                launch: launch({ state: 'on_hold', checks: [], can: { ...launch().can, approve: false } }),
                canApprove: true,
                writesOn: true,
                busy: false,
                isAdmin: false,
            },
        });

        expect(approveButton(blocked).attributes('disabled')).toBeDefined();
        expect(approveButton(off).attributes('disabled')).toBeDefined();
        expect(approveButton(held).attributes('disabled')).toBeDefined();
    });

    it('says when the parent is paused (D4) and when approving spends at once', () => {
        const paused = mount(ApprovalCard, {
            props: {
                launch: launch({ money: { cap: 20000, currency: 'EGP', parent_status: 'PAUSED', campaign_status: 'ACTIVE' } }),
                canApprove: true,
                writesOn: true,
                busy: false,
                isAdmin: false,
            },
        });
        const active = mount(ApprovalCard, { props: { launch: launch(), canApprove: true, writesOn: true, busy: false, isAdmin: false } });

        expect(paused.text()).toContain('مش هيصرف');
        expect(active.text()).toContain('فورا');
    });
});
