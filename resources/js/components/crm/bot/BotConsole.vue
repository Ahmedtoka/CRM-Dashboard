<script setup lang="ts">
import BotTraceView from '@/components/crm/bot/BotTraceView.vue';
import { Button } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { formatUsd } from '@/lib/format';
import type { BotTrace } from '@/types/botBrain';
import { Bot, ChevronDown, RotateCcw, SendHorizontal } from 'lucide-vue-next';
import { nextTick, ref } from 'vue';

/**
 * «جرّبي البوت» (b2): types a customer message, the real bot answers in a sandbox (nothing sent,
 * nothing saved), and each answer opens its full trace. The chat carries on: the earlier lines and
 * the bot state go back with every message.
 */
interface Turn {
    id: number;
    text: string;
    replies: { text: string; buttons: string[]; cards: number }[];
    trace: BotTrace | null;
    handover: boolean;
    flow: string | null;
    spent: number;
    noModel: boolean;
    error: string | null;
    open: boolean;
}

const api = useApi();
const { t, locale } = useI18n();

const draft = ref('');
const sending = ref(false);
const turns = ref<Turn[]>([]);
const state = ref<Record<string, unknown> | null>(null);
const ended = ref(false);
const list = ref<HTMLElement | null>(null);
let seq = 0;

function history(): { role: 'customer' | 'bot'; text: string }[] {
    return turns.value.flatMap((turn) => [
        { role: 'customer' as const, text: turn.text },
        ...turn.replies.filter((r) => r.text.trim() !== '').map((r) => ({ role: 'bot' as const, text: r.text })),
    ]);
}

async function send(): Promise<void> {
    const text = draft.value.trim();
    if (!text || sending.value || ended.value) return;
    sending.value = true;
    const prior = history();
    const turn: Turn = { id: ++seq, text, replies: [], trace: null, handover: false, flow: null, spent: 0, noModel: false, error: null, open: false };
    turns.value.push(turn);
    draft.value = '';
    await scroll();
    try {
        const { data } = await api.post('/settings/bot-brain/try', { text, history: prior, state: state.value });
        const res = data.data;
        const live = turns.value.find((x) => x.id === turn.id)!;
        live.replies = res.replies ?? [];
        live.trace = res.trace ?? null;
        live.handover = !!res.handover;
        live.flow = res.flow ?? null;
        live.spent = Number(res.spent_usd ?? 0);
        live.noModel = !res.agent_ready || !res.ai_enabled;
        live.error = res.error ? t('bot_brain.brain.console.error', { message: res.error }) : null;
        state.value = res.state ?? null;
        ended.value = live.handover;
    } catch (e) {
        turns.value.find((x) => x.id === turn.id)!.error = apiErrorMessage(e, t('common.error'));
    } finally {
        sending.value = false;
        await scroll();
    }
}

function reset(): void {
    turns.value = [];
    state.value = null;
    ended.value = false;
    draft.value = '';
}

async function scroll(): Promise<void> {
    await nextTick();
    list.value?.scrollTo?.({ top: list.value.scrollHeight });
}
</script>

<template>
    <section
        class="flex flex-col overflow-hidden rounded-lg border border-line bg-card"
        aria-labelledby="bot-console-title"
        data-testid="bot-console"
    >
        <header class="flex items-start justify-between gap-3 border-b border-line px-4 py-3">
            <div>
                <h2 id="bot-console-title" class="text-base font-bold">{{ t('bot_brain.brain.console.title') }}</h2>
                <p class="text-13 text-fg-2">{{ t('bot_brain.brain.console.hint') }}</p>
            </div>
            <Button v-if="turns.length" variant="ghost" size="sm" class="shrink-0" @click="reset">
                <RotateCcw class="size-4" aria-hidden="true" />{{ t('bot_brain.brain.console.reset') }}
            </Button>
        </header>

        <div ref="list" class="max-h-[32rem] min-h-48 flex-1 space-y-3 overflow-y-auto bg-background/60 px-4 py-4" aria-live="polite">
            <p v-if="!turns.length" class="py-10 text-center text-13 text-fg-3">{{ t('bot_brain.brain.console.empty') }}</p>
            <div v-for="turn in turns" :key="turn.id" class="space-y-2">
                <div class="flex justify-start">
                    <p class="max-w-[80%] whitespace-pre-wrap rounded-xl bg-[var(--bubble-in)] px-3 py-2 text-sm shadow-e1" dir="auto">
                        <span class="sr-only">{{ t('bot_brain.brain.console.you') }}: </span>{{ turn.text }}
                    </p>
                </div>
                <div v-if="!turn.replies.length && !turn.error && sending && turn.id === turns[turns.length - 1].id" class="flex justify-end">
                    <p class="rounded-xl bg-[var(--bubble-bot)] px-3 py-2 text-13 text-fg-2">{{ t('bot_brain.brain.console.thinking') }}…</p>
                </div>
                <div v-for="(reply, i) in turn.replies" :key="i" class="flex justify-end">
                    <div class="max-w-[80%] rounded-xl bg-[var(--bubble-bot)] px-3 py-2 text-sm shadow-e1">
                        <p class="mb-0.5 flex items-center gap-1 text-[11.5px] font-semibold text-fg-2">
                            <Bot class="size-3.5" aria-hidden="true" />{{ t('bot_brain.brain.console.bot') }}
                        </p>
                        <p class="whitespace-pre-wrap" dir="auto">{{ reply.text }}</p>
                        <div v-if="reply.buttons.length" class="mt-1.5 flex flex-wrap gap-1">
                            <span v-for="b in reply.buttons" :key="b" class="text-12 rounded-full border border-line bg-card px-2.5 py-0.5">{{
                                b
                            }}</span>
                        </div>
                        <p v-if="reply.cards" class="text-12 mt-1 text-fg-3">{{ t('bot_brain.brain.console.cards', { n: reply.cards }) }}</p>
                    </div>
                </div>
                <p v-if="turn.error" class="text-13 text-danger" role="alert">{{ turn.error }}</p>
                <template v-else-if="turn.trace || turn.replies.length">
                    <p v-if="!turn.replies.length && !turn.handover" class="text-end text-13 text-fg-3">
                        {{ t('bot_brain.brain.console.no_reply') }}
                    </p>
                    <div class="text-12 flex flex-wrap items-center justify-end gap-x-3 gap-y-1 text-fg-3">
                        <span v-if="turn.noModel">{{ t('bot_brain.brain.console.no_model') }}</span>
                        <span v-if="turn.flow">{{ t('bot_brain.brain.console.flow', { flow: turn.flow }) }}</span>
                        <span v-if="turn.spent > 0">{{ t('bot_brain.brain.console.spent', { usd: formatUsd(turn.spent, locale) }) }}</span>
                        <button
                            v-if="turn.trace"
                            type="button"
                            class="inline-flex items-center gap-1 font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :aria-expanded="turn.open"
                            @click="turn.open = !turn.open"
                        >
                            {{ t('bot_brain.brain.console.why') }}
                            <ChevronDown class="size-3.5 transition-transform" :class="turn.open && 'rotate-180'" aria-hidden="true" />
                        </button>
                    </div>
                    <div v-if="turn.open && turn.trace" class="rounded-lg border border-line bg-card p-3">
                        <BotTraceView :trace="turn.trace" />
                    </div>
                    <p v-if="turn.handover" class="rounded-md bg-info-soft px-3 py-2 text-13 text-info">
                        {{ t('bot_brain.brain.console.handover') }}
                    </p>
                </template>
            </div>
        </div>

        <form class="flex items-end gap-2 border-t border-line p-3" @submit.prevent="send">
            <label class="sr-only" for="bot-console-input">{{ t('bot_brain.brain.console.placeholder') }}</label>
            <textarea
                id="bot-console-input"
                v-model="draft"
                rows="1"
                maxlength="1000"
                :disabled="ended"
                :placeholder="t('bot_brain.brain.console.placeholder')"
                class="max-h-32 min-h-10 flex-1 resize-none rounded-md border border-line-strong bg-card px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                dir="auto"
                @keydown.enter.exact.prevent="send"
            />
            <Button type="submit" class="h-10" :disabled="sending || ended || !draft.trim()">
                <SendHorizontal class="size-4 rtl:-scale-x-100" aria-hidden="true" />{{ t('bot_brain.brain.console.send') }}
            </Button>
        </form>
    </section>
</template>
