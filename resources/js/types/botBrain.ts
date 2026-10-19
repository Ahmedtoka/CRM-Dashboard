/** «عقل البوت» (b2, 2026-10-19): shapes served by app/Bot/Brain and the Web/Bot controllers. */

export type BotLayer = 'script' | 'rule' | 'flow' | 'agent' | 'fallback' | 'handover';

export const BOT_LAYERS: BotLayer[] = ['script', 'rule', 'flow', 'agent', 'fallback', 'handover'];

export type FeedbackReason = 'misunderstood' | 'wrong_info' | 'should_handover' | 'needless_handover' | 'repeated' | 'slow' | 'tone' | 'other';

export const FEEDBACK_REASONS: FeedbackReason[] = [
    'misunderstood',
    'wrong_info',
    'should_handover',
    'needless_handover',
    'repeated',
    'slow',
    'tone',
    'other',
];

export interface TraceCall {
    phase: 'planner' | 'agent' | 'understanding' | 'reply';
    model: string;
    in: number;
    out: number;
    cache_read: number;
    cache_write: number;
    cost: number;
    ms: number;
    stop: string | null;
}

export interface TraceTool {
    name: string;
    input: string;
    output: string | null;
}

export interface TracePlanTask {
    kind?: string;
    order_refs?: string[];
    items?: string[];
    product_query?: string;
    question?: string;
    confidence?: number;
}

export interface BotTrace {
    id: number | null;
    bot_run_id: number;
    conversation_id: number | null;
    at: string | null;
    trigger: string | null;
    router: 'legacy' | 'shadow' | 'hybrid' | null;
    engine: string;
    decision: string;
    layer: BotLayer;
    tier: string | null;
    kinds: string[];
    reason: string | null;
    tools_used: string[];
    rule: { id: number; name: string | null } | null;
    plan: { tasks: TracePlanTask[]; sentiment: string | null; wants_human: boolean; policy_edge: boolean; clarify: string | null } | null;
    calls: TraceCall[];
    tools: TraceTool[];
    guards: string[];
    handover: {
        reason: string | null;
        category: string | null;
        priority: string | null;
        queue: string | null;
        queue_entry_id: number | null;
        queue_status?: string | null;
        assignee?: string | null;
    } | null;
    message_ids: number[];
    model: string | null;
    cost_usd: number;
    tokens: { in: number; out: number; cache_read: number };
    timing: {
        total_ms: number;
        burst_wait_ms: number | null;
        planner_ms: number | null;
        llm_ms: number | null;
        tools_ms: number | null;
        rounds: number | null;
    };
}

export interface MyFeedback {
    id: number;
    reasons: FeedbackReason[];
    note: string | null;
    correct_reply: string | null;
    status: 'open' | 'converted' | 'dismissed';
}
