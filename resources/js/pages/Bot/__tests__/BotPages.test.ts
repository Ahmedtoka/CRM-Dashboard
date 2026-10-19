import { flushPromises, mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

const { get, post, put, push, reload, visit } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    push: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
}));
vi.mock('@/composables/useApi', () => ({ useApi: () => ({ get, post, put }), apiErrorMessage: (_e: unknown, f: string) => f }));
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ push }) }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { reload, get: visit },
    usePage: () => ({ url: '/bot/feedback', props: { auth: { user: { role: 'admin' } } } }),
}));

import { setCurrentLocale } from '@/composables/useI18n';
import Brain from '@/pages/Bot/Brain.vue';
import Costs from '@/pages/Bot/Costs.vue';
import Feedback from '@/pages/Bot/Feedback.vue';

const stubs = {
    AppLayout: { template: '<div><slot /></div>' },
    SectionTabs: true,
    BotConsole: { template: '<div data-testid="console" />' },
    BarChart: true,
    DataTable: true,
    Ledger: true,
    Sheet: true,
};

beforeAll(() => setCurrentLocale('ar'));
beforeEach(() => [get, post, put, push, reload, visit].forEach((f) => f.mockReset()));

const item = {
    id: 4,
    reasons: ['should_handover'],
    note: null,
    correct_reply: 'كان لازم تحوّل',
    status: 'open',
    converted_to: null,
    suggestion_id: null,
    layer: 'agent',
    tier: '1',
    by: 'منى',
    at: '2026-10-19T09:00:00Z',
    reply: 'تحت أمرك',
    trigger: 'عايزة اكلم المدير',
    model: 'claude-haiku-5-5',
    conversation_id: 12,
    message_id: 30,
    trace_id: 8,
};
const feedbackProps = {
    filters: { status: 'open' },
    items: { data: [item], current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null, total: 1 },
    counts: {
        open: 1,
        last_30: 1,
        by_reason: { misunderstood: 0, wrong_info: 0, should_handover: 1, needless_handover: 0, repeated: 0, slow: 0, tone: 0, other: 0 },
    },
    reasons: ['misunderstood', 'wrong_info', 'should_handover', 'needless_handover', 'repeated', 'slow', 'tone', 'other'],
    layers: ['script', 'rule', 'flow', 'agent', 'fallback', 'handover'],
};

describe('Bot/Feedback', () => {
    it('turns a note into an eval scenario prefilled from her message and the reason', async () => {
        post.mockResolvedValue({ data: { data: { suggestion_id: 1 } } });
        const w = mount(Feedback, { props: feedbackProps as never, global: { stubs } });
        expect(w.text()).toContain('عايزة اكلم المدير');
        expect(w.find('a[href="/inbox?c=12"]').exists()).toBe(true);

        await w.find('[data-convert="eval"]').trigger('click');
        expect((w.find('form textarea').element as HTMLTextAreaElement).value).toBe('عايزة اكلم المدير');
        await w.find('form').trigger('submit');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/settings/bot-feedback/4/convert', {
            kind: 'eval',
            messages: ['عايزة اكلم المدير'],
            expect: { handover: true, says: [], never: [] },
        });
        expect(reload).toHaveBeenCalled();
    });

    it('filters by reason from the count chips', async () => {
        const w = mount(Feedback, { props: feedbackProps as never, global: { stubs } });
        await w.find('section [data-reason="should_handover"]').trigger('click');
        expect(visit).toHaveBeenCalledWith('/bot/feedback', { status: 'open', reason: 'should_handover' }, expect.anything());
    });
});

const report = {
    spend: { today: 0.5, week: 1.4, month: 3, avg_day_7: 0.2 },
    daily: [{ date: '2026-10-19', usd: 0.5 }],
    by_model: [],
    conversations: { count: 10, usd: 3, per_conversation: 0.3, per_1000: 300 },
    cache: { read: 800, input: 1000, share: 0.8 },
    balance: {
        set: true,
        entered_usd: 10,
        entered_at: '2026-10-19T00:00:00Z',
        spent_since: 0.7,
        left_usd: 0.5,
        days_left: 2.5,
        runs_out_on: '2026-10-21',
        warn: true,
    },
    sonnet: { spent_today: 1, cap: 5, hit: false },
    scenarios: {
        month_turns: 30,
        legacy: { per_turn: 0.007, month: 0.21, measured: false },
        hybrid: { per_turn: 0.0004, month: 0.01, measured: true },
    },
    console_today: 0,
};

describe('Bot/Costs', () => {
    it('says how many days the balance lasts and warns under 3 days', () => {
        const w = mount(Costs, { props: { report, canEditBalance: true, nowLocal: '2026-10-19T12:00' } as never, global: { stubs } });
        expect(w.find('[data-testid="days-left"]').text()).toContain('الرصيد هيكفي حوالي');
        expect(w.text()).toContain('أقل من ٣ أيام');
        expect(w.find('[data-scenario="legacy"]').text()).toContain('تقدير من رد نموذجي');
    });

    it('lets an admin save the balance, and asks for it when it is missing', async () => {
        put.mockResolvedValue({ data: { data: { saved: true } } });
        const w = mount(Costs, {
            props: { report: { ...report, balance: { set: false } }, canEditBalance: true, nowLocal: '2026-10-19T12:00' } as never,
            global: { stubs },
        });
        expect(w.text()).toContain('لسه الرصيد مش متسجل');
        await w.find('input[type="number"]').setValue('100');
        await w.find('form').trigger('submit');
        await flushPromises();
        expect(put).toHaveBeenCalledWith('/settings/bot-balance', expect.objectContaining({ balance_usd: 100 }));

        const sup = mount(Costs, { props: { report, canEditBalance: false, nowLocal: '2026-10-19T12:00' } as never, global: { stubs } });
        expect(sup.find('form').exists()).toBe(false);
        expect(sup.text()).toContain('الأدمن بس يقدر يغيّر الرصيد');
    });
});

describe('Bot/Brain', () => {
    const brain = {
        state: { bot_enabled: true, ai_enabled: true, agent_ready: false },
        router: {
            mode: 'hybrid',
            legacy_fallback: false,
            models: { planner: 'claude-haiku-5-5', tier1: 'claude-haiku-5-5', tier2: 'claude-sonnet-5-5', legacy: 'claude-sonnet-5-5' },
            effort: { tier1: 'low', tier2: 'medium', legacy: 'medium' },
            sonnet_cap: { cap: 5, spent_today: 0, hit: false },
            max_tasks: 4,
        },
        tools: [{ name: 'search_products', description: 'Search the store catalog' }],
        handover: {
            keywords: ['موظف'],
            max_bot_turns: 6,
            max_tasks: 4,
            reasons: ['keyword'],
            escalations: ['E1'],
            queue_on: true,
            hours_configured: true,
        },
        guards: ['price'],
        layers_7d: { script: 3, rule: 0, flow: 2, agent: 5, fallback: 0, handover: 1 },
        prompt: {
            text: 'You are ميار',
            chars: 12,
            approx_tokens: 3,
            parts: [
                { key: 'rules', editable: false, chars: 10 },
                { key: 'instructions', editable: true, href: '/bot/replies', chars: 2 },
            ],
            context_lines: ['channel'],
        },
        sources: [{ key: 'catalog', count: 120, updated_at: null, href: '/products' }],
        pending: { suggestions: 2, feedback: 0 },
    };

    it('shows admins the console, the prompt behind a toggle, and a warning when no model is available', async () => {
        const w = mount(Brain, { props: { full: true, brain } as never, global: { stubs } });
        expect(w.find('[data-testid="console"]').exists()).toBe(true);
        expect(w.find('[data-testid="router-mode"]').text()).toContain('الجديدة');
        expect(w.text()).toContain('مفيش مفتاح Anthropic');
        expect(w.find('[data-testid="prompt-text"]').exists()).toBe(false);
        await w
            .findAll('button')
            .find((b) => b.text().includes('اعرضي النص كله'))!
            .trigger('click');
        expect(w.find('[data-testid="prompt-text"]').text()).toContain('You are ميار');
        expect(w.find('[data-part="instructions"] a').attributes('href')).toBe('/bot/replies');
    });

    it('gives moderators the summary only', () => {
        const summary: Record<string, unknown> = { ...brain };
        delete summary.prompt;
        delete summary.sources;
        delete summary.pending;
        const w = mount(Brain, { props: { full: false, brain: summary } as never, global: { stubs } });
        expect(w.find('[data-testid="console"]').exists()).toBe(false);
        expect(w.text()).not.toContain('التعليمات اللي بتتبعت للموديل');
        expect(w.text()).toContain('بيدوّر في الكتالوج');
    });
});
