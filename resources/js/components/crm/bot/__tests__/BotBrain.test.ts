import { flushPromises, mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

const { get, post, push } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), push: vi.fn() }));
vi.mock('@/composables/useApi', () => ({ useApi: () => ({ get, post }), apiErrorMessage: (_e: unknown, f: string) => f }));
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ push }) }));
vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));

import BotConsole from '@/components/crm/bot/BotConsole.vue';
import BotFeedbackForm from '@/components/crm/bot/BotFeedbackForm.vue';
import BotLayerLadder from '@/components/crm/bot/BotLayerLadder.vue';
import BotReplyActions from '@/components/crm/bot/BotReplyActions.vue';
import BotTraceView from '@/components/crm/bot/BotTraceView.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import type { BotTrace } from '@/types/botBrain';

const passthrough = { template: '<div><slot /></div>' };
const sheetStubs = {
    Sheet: passthrough,
    SheetContent: passthrough,
    SheetHeader: passthrough,
    SheetTitle: { template: '<h2><slot /></h2>' },
    SheetDescription: { template: '<p><slot /></p>' },
};

function trace(patch: Partial<BotTrace> = {}): BotTrace {
    return {
        id: 3,
        bot_run_id: 9,
        conversation_id: 1,
        at: '2026-10-19T10:00:00Z',
        trigger: 'الشحن للجيزة بكام؟',
        router: 'hybrid',
        engine: 'hybrid',
        decision: 'reply',
        layer: 'agent',
        tier: '1',
        kinds: ['shipping_fee'],
        reason: null,
        tools_used: ['get_shipping_fee'],
        rule: null,
        plan: { tasks: [{ kind: 'shipping_fee' }], sentiment: 'neutral', wants_human: false, policy_edge: false, clarify: null },
        calls: [
            {
                phase: 'planner',
                model: 'claude-haiku-5-5',
                in: 300,
                out: 60,
                cache_read: 1200,
                cache_write: 0,
                cost: 0.0001,
                ms: 420,
                stop: 'end_turn',
            },
            {
                phase: 'agent',
                model: 'claude-haiku-5-5',
                in: 400,
                out: 40,
                cache_read: 3000,
                cache_write: 0,
                cost: 0.0002,
                ms: 900,
                stop: 'end_turn',
            },
        ],
        tools: [{ name: 'get_shipping_fee', input: '{"governorate":"الجيزة"}', output: '60 جنيه' }],
        guards: ['pass'],
        handover: null,
        message_ids: [5],
        model: 'claude-haiku-5-5',
        cost_usd: 0.0003,
        tokens: { in: 700, out: 100, cache_read: 4200 },
        timing: { total_ms: 1500, burst_wait_ms: null, planner_ms: 420, llm_ms: 900, tools_ms: 20, rounds: 2 },
        ...patch,
    };
}

beforeAll(() => setCurrentLocale('ar'));
beforeEach(() => {
    get.mockReset();
    post.mockReset();
    push.mockReset();
});

describe('BotLayerLadder', () => {
    it('lists the six layers in order with their counts and marks the one that answered', () => {
        const w = mount(BotLayerLadder, { props: { counts: { script: 12, agent: 30, handover: 4 }, active: 'agent' } });
        const items = w.findAll('li');
        expect(items.map((li) => li.attributes('data-layer'))).toEqual(['script', 'rule', 'flow', 'agent', 'fallback', 'handover']);
        expect(w.find('[data-layer="agent"]').attributes('aria-current')).toBe('step');
        expect(w.find('[data-layer="rule"] [data-count]').attributes('data-count')).toBe('0');
        expect(w.find('[data-layer="agent"]').text()).toContain('الموديل');
    });
});

describe('BotTraceView', () => {
    it('shows the layer, what the planner understood, the tool with its input and result, and the passed guards', () => {
        const w = mount(BotTraceView, { props: { trace: trace() } });
        expect(w.find('[data-testid="trace-layer"]').text()).toBe('الموديل');
        expect(w.text()).toContain('مستوى ١: Haiku');
        expect(w.text()).toContain('سعر الشحن');
        expect(w.text()).toContain('60 جنيه');
        expect(w.text()).toContain('عدّى كل الحراسات');
        expect(w.text()).toContain('المخطِّط');
    });

    it('explains a failed guard, the escalation reason and a handover to the queue', () => {
        const w = mount(BotTraceView, {
            props: {
                trace: trace({
                    guards: ['price'],
                    reason: 'E3:budget',
                    layer: 'handover',
                    handover: { reason: 'human_request', category: null, priority: 'high', queue: 'agents', queue_entry_id: 4, assignee: 'منى' },
                }),
            },
        });
        expect(w.text()).toContain('وقع في حراسة: السعر');
        expect(w.text()).toContain('حدود السياسة');
        expect(w.text()).toContain('حد Sonnet خلص');
        expect(w.text()).toContain('راح للطابور');
        expect(w.text()).toContain('مع منى دلوقتي');
        expect(w.text()).toContain('مستعجل');
    });

    it('says a turn without a model was free', () => {
        const w = mount(BotTraceView, {
            props: {
                trace: trace({ calls: [], cost_usd: 0, tools: [], tools_used: [], guards: [], plan: null, kinds: [], layer: 'script', tier: '0' }),
            },
        });
        expect(w.text()).toContain('ببلاش');
    });
});

describe('BotFeedbackForm', () => {
    it('needs a reason, then sends the chips and the correct reply', async () => {
        post.mockResolvedValue({ data: { data: { id: 1, reasons: ['wrong_info'], note: null, correct_reply: 'الشحن 60', status: 'open' } } });
        const w = mount(BotFeedbackForm, { props: { messageId: 5, existing: null } });

        await w.find('form').trigger('submit');
        expect(post).not.toHaveBeenCalled();
        expect(w.text()).toContain('اختاري سبب واحد على الأقل');

        await w.find('[data-reason="wrong_info"]').trigger('click');
        await w.find('textarea').setValue('الشحن 60');
        await w.find('form').trigger('submit');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/inbox/messages/5/bot-feedback', { reasons: ['wrong_info'], note: null, correct_reply: 'الشحن 60' });
        expect(w.emitted('saved')?.[0][0]).toMatchObject({ reasons: ['wrong_info'] });
    });

    it('asks what happened when «تاني» is picked, and prefills an earlier note', async () => {
        const w = mount(BotFeedbackForm, {
            props: { messageId: 5, existing: { id: 1, reasons: ['tone'], note: null, correct_reply: null, status: 'open' } },
        });
        expect(w.find('[data-reason="tone"]').attributes('aria-pressed')).toBe('true');
        expect(w.find('input').exists()).toBe(false);
        await w.find('[data-reason="other"]').trigger('click');
        expect(w.find('input').exists()).toBe(true);
        expect(w.text()).toContain('عدّلي الملاحظة');
    });
});

describe('BotReplyActions', () => {
    it('loads the trace once and shows it with the feedback form; an old reply says there are no details', async () => {
        get.mockResolvedValueOnce({ data: { data: trace(), feedback: null } });
        const w = mount(BotReplyActions, { props: { messageId: 5 }, global: { stubs: sheetStubs } });
        await w.find('[data-action="trace"]').trigger('click');
        await flushPromises();
        expect(get).toHaveBeenCalledWith('/inbox/messages/5/bot-trace');
        expect(w.find('[data-testid="bot-trace"]').exists()).toBe(true);
        expect(w.find('[data-testid="bot-feedback-form"]').exists()).toBe(true);
        await w.find('[data-action="feedback"]').trigger('click');
        expect(get).toHaveBeenCalledTimes(1);

        get.mockResolvedValueOnce({ data: { data: null, feedback: null } });
        const old = mount(BotReplyActions, { props: { messageId: 6 }, global: { stubs: sheetStubs } });
        await old.find('[data-action="trace"]').trigger('click');
        await flushPromises();
        expect(old.text()).toContain('مفيش تفاصيل للرد ده');
    });
});

describe('BotConsole', () => {
    it('sends each message with the earlier lines and the bot state, and opens the trace of an answer', async () => {
        post.mockResolvedValueOnce({
            data: {
                data: {
                    replies: [{ text: 'عندنا عبايات', buttons: ['القائمة'], cards: 0 }],
                    trace: trace(),
                    handover: false,
                    flow: null,
                    state: { a: 1 },
                    spent_usd: 0.0003,
                    error: null,
                    agent_ready: true,
                    ai_enabled: true,
                },
            },
        });
        post.mockResolvedValueOnce({
            data: {
                data: {
                    replies: [],
                    trace: trace({ layer: 'handover' }),
                    handover: true,
                    flow: null,
                    state: null,
                    spent_usd: 0,
                    error: null,
                    agent_ready: true,
                    ai_enabled: true,
                },
            },
        });
        const w = mount(BotConsole);

        await w.find('textarea').setValue('عندكم عبايات؟');
        await w.find('form').trigger('submit');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/settings/bot-brain/try', { text: 'عندكم عبايات؟', history: [], state: null });
        expect(w.text()).toContain('عندنا عبايات');
        expect(w.text()).toContain('القائمة');

        await w
            .findAll('button')
            .find((b) => b.text().includes('ليه رد كده؟'))!
            .trigger('click');
        expect(w.find('[data-testid="bot-trace"]').exists()).toBe(true);

        await w.find('textarea').setValue('عايزة اكلم حد');
        await w.find('form').trigger('submit');
        await flushPromises();
        expect(post.mock.calls[1][1]).toEqual({
            text: 'عايزة اكلم حد',
            history: [
                { role: 'customer', text: 'عندكم عبايات؟' },
                { role: 'bot', text: 'عندنا عبايات' },
            ],
            state: { a: 1 },
        });
        expect(w.text()).toContain('هنا البوت حوّل الشات لموظفة');
        expect(w.find('textarea').attributes('disabled')).toBeDefined();
    });
});
