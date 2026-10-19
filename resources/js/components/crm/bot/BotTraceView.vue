<script setup lang="ts">
import BotLayerLadder from '@/components/crm/bot/BotLayerLadder.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { translate } from '@/i18n';
import { formatCount, formatDateTime, formatUsd } from '@/lib/format';
import type { BotTrace, TracePlanTask } from '@/types/botBrain';
import { ShieldCheck, ShieldX, Wrench } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * «ليه البوت رد كده؟» (b2): one bot turn — the layer that answered on the ladder, what the planner
 * understood, why that tier, the tools with what they asked and got, the guards, the handover, and
 * every model call with its tokens, cost and time. Shared by the inbox sheet, the test console and
 * the feedback review.
 */
const props = withDefaults(defineProps<{ trace: BotTrace; showCost?: boolean }>(), { showCost: true });

const { t, locale } = useI18n();

/** A key that may be missing (an unknown reason or kind): the raw value instead of the key. */
const label = (key: string, raw: string) => {
    const text = translate(locale.value, key);
    return text === key ? raw : text;
};

const seconds = (ms: number | null | undefined) =>
    t('bot_brain.trace.seconds', {
        n: (Math.max(0, ms ?? 0) / 1000).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en', { maximumFractionDigits: 1 }),
    });

const tierLabel = computed(() => (props.trace.tier ? label(`bot_brain.trace.tier.${props.trace.tier}`, props.trace.tier) : null));

const reasonLabel = computed(() => {
    const reason = props.trace.reason;
    if (!reason) return null;
    const [code, budget] = reason.split(':');
    const text = label(`bot_brain.trace.reasons.${code}`, code);
    return budget ? `${text} (${t('bot_brain.trace.reasons.budget')})` : text;
});

const kinds = computed(() => {
    const fromPlan = (props.trace.plan?.tasks ?? []).map((task) => task.kind).filter((k): k is string => !!k);
    return fromPlan.length ? fromPlan : props.trace.kinds;
});

const planTasks = computed<TracePlanTask[]>(() => props.trace.plan?.tasks ?? kinds.value.map((kind) => ({ kind })));

const totalCost = computed(() => props.trace.calls.reduce((sum, c) => sum + c.cost, 0) || props.trace.cost_usd);
const guardFailure = computed(() => props.trace.guards.find((g) => g !== 'pass') ?? null);
const handoverReason = computed(() => {
    const reason = props.trace.handover?.reason;
    return reason ? label(`bot_brain.trace.handover_reasons.${reason}`, label(`reports.reasons.${reason}`, reason)) : null;
});
</script>

<template>
    <div class="space-y-4 text-sm" data-testid="bot-trace">
        <section class="space-y-2">
            <BotLayerLadder :active="trace.layer" compact />
            <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span class="text-fg-2">{{ t('bot_brain.trace.answered_by') }}:</span>
                <span class="font-semibold" data-testid="trace-layer">{{ t(`bot_brain.brain.layers.${trace.layer}`) }}</span>
                <StatusChip v-if="tierLabel" :label="tierLabel" tone="brand" size="sm" />
                <span v-if="trace.at" class="text-12 text-fg-3">{{ formatDateTime(trace.at, locale) }}</span>
            </p>
        </section>

        <section v-if="trace.trigger" class="space-y-1">
            <h3 class="text-12 font-semibold text-fg-2">{{ t('bot_brain.trace.her_message') }}</h3>
            <p class="whitespace-pre-wrap rounded-md bg-sunken px-3 py-2 leading-relaxed" dir="auto">{{ trace.trigger }}</p>
        </section>

        <section v-if="kinds.length || trace.plan" class="space-y-1.5">
            <h3 class="text-12 font-semibold text-fg-2">{{ t('bot_brain.trace.plan_title') }}</h3>
            <ul class="flex flex-wrap gap-1.5">
                <li v-for="(task, i) in planTasks" :key="i">
                    <StatusChip :label="label(`bot_brain.trace.kinds.${task.kind}`, task.kind ?? '')" tone="info" />
                    <bdi v-if="task.order_refs?.length" class="text-12 ms-1 tabular-nums text-fg-2" dir="ltr">#{{ task.order_refs.join(' #') }}</bdi>
                    <span v-if="task.product_query" class="text-12 ms-1 text-fg-2" dir="auto">«{{ task.product_query }}»</span>
                </li>
            </ul>
            <p v-if="trace.plan?.sentiment && trace.plan.sentiment !== 'neutral'" class="text-12 text-fg-2">
                {{ label(`bot_brain.trace.sentiment.${trace.plan.sentiment}`, trace.plan.sentiment) }}
            </p>
        </section>

        <section v-if="reasonLabel || trace.rule" class="space-y-1">
            <h3 class="text-12 font-semibold text-fg-2">{{ t('bot_brain.trace.reason_title') }}</h3>
            <p v-if="reasonLabel">{{ reasonLabel }}</p>
            <p v-if="trace.rule">{{ t('bot_brain.trace.rule', { name: trace.rule.name ?? `#${trace.rule.id}` }) }}</p>
        </section>

        <section v-if="trace.tools.length || trace.tools_used.length" class="space-y-1.5">
            <h3 class="text-12 font-semibold text-fg-2">{{ t('bot_brain.trace.tools_title') }}</h3>
            <ul class="space-y-2">
                <li
                    v-for="(tool, i) in trace.tools.length ? trace.tools : trace.tools_used.map((name) => ({ name, input: '', output: null }))"
                    :key="i"
                    class="rounded-md border border-line p-2.5"
                >
                    <p class="flex items-center gap-1.5 font-semibold">
                        <Wrench class="size-3.5 text-fg-3" aria-hidden="true" />
                        <bdi dir="ltr">{{ tool.name }}</bdi>
                    </p>
                    <p class="text-12 mt-0.5 text-fg-2">{{ label(`bot_brain.brain.tools.${tool.name}`, '') }}</p>
                    <dl v-if="tool.input || tool.output !== null" class="text-12 mt-1.5 grid grid-cols-[auto_minmax(0,1fr)] gap-x-2 gap-y-1">
                        <dt v-if="tool.input" class="text-fg-3">{{ t('bot_brain.trace.input') }}</dt>
                        <dd v-if="tool.input" class="break-words">
                            <bdi dir="ltr">{{ tool.input }}</bdi>
                        </dd>
                        <dt class="text-fg-3">{{ t('bot_brain.trace.output') }}</dt>
                        <dd class="break-words" dir="auto">{{ tool.output ?? t('bot_brain.trace.no_output') }}</dd>
                    </dl>
                </li>
            </ul>
        </section>

        <section v-if="trace.guards.length" class="space-y-1">
            <h3 class="text-12 font-semibold text-fg-2">{{ t('bot_brain.trace.guards_title') }}</h3>
            <StatusChip
                v-if="guardFailure"
                :label="t('bot_brain.trace.guard_fail', { guard: label(`bot_brain.trace.guard_names.${guardFailure}`, guardFailure) })"
                tone="danger"
                :icon="ShieldX"
            />
            <StatusChip v-else :label="t('bot_brain.trace.guard_pass')" tone="ok" :icon="ShieldCheck" />
        </section>

        <section v-if="trace.handover" class="space-y-1">
            <h3 class="text-12 font-semibold text-fg-2">{{ t('bot_brain.trace.handover_title') }}</h3>
            <p v-if="handoverReason">{{ t('bot_brain.trace.handover_reason', { reason: handoverReason }) }}</p>
            <p class="flex flex-wrap items-center gap-1.5">
                <span>{{ trace.handover.queue_entry_id ? t('bot_brain.trace.to_queue') : t('bot_brain.trace.to_team') }}</span>
                <StatusChip v-if="trace.handover.priority === 'high'" :label="t('bot_brain.trace.priority_high')" tone="warn" size="sm" />
                <span v-if="trace.handover.assignee" class="text-fg-2">{{ t('bot_brain.trace.assignee', { name: trace.handover.assignee }) }}</span>
            </p>
        </section>

        <section v-if="showCost" class="space-y-1.5" data-testid="trace-cost">
            <h3 class="text-12 font-semibold text-fg-2">{{ t('bot_brain.trace.calls_title') }}</h3>
            <p v-if="!trace.calls.length" class="text-fg-2">
                {{ trace.cost_usd > 0 ? formatUsd(trace.cost_usd, locale) : t('bot_brain.trace.free') }}
            </p>
            <table v-else class="text-12 w-full">
                <thead>
                    <tr class="border-b border-line text-start text-fg-3">
                        <th class="py-1 pe-2 text-start font-medium">{{ t('bot_brain.trace.col_step') }}</th>
                        <th class="py-1 pe-2 text-start font-medium">{{ t('bot_brain.trace.col_tokens') }}</th>
                        <th class="py-1 pe-2 text-start font-medium">{{ t('bot_brain.trace.latency') }}</th>
                        <th class="py-1 text-end font-medium">{{ t('bot_brain.trace.col_cost') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(call, i) in trace.calls" :key="i" class="border-b border-line align-top last:border-0">
                        <td class="py-1.5 pe-2">
                            <span class="font-semibold">{{ t(`bot_brain.trace.phase.${call.phase}`) }}</span>
                            <bdi class="block whitespace-nowrap text-fg-3" dir="ltr">{{ call.model }}</bdi>
                        </td>
                        <td class="py-1.5 pe-2 text-fg-2">
                            {{
                                t('bot_brain.trace.tokens', {
                                    in: formatCount(call.in + call.cache_read + call.cache_write, locale),
                                    out: formatCount(call.out, locale),
                                })
                            }}
                            <span v-if="call.cache_read" class="block text-fg-3">{{
                                t('bot_brain.trace.cache', { n: formatCount(call.cache_read, locale) })
                            }}</span>
                        </td>
                        <td class="py-1.5 pe-2 tabular-nums">{{ seconds(call.ms) }}</td>
                        <td class="py-1.5 text-end tabular-nums">{{ formatUsd(call.cost, locale) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <td class="pt-1.5">{{ t('bot_brain.trace.total') }}</td>
                        <td />
                        <td class="pt-1.5 tabular-nums">{{ seconds(trace.timing.total_ms) }}</td>
                        <td class="pt-1.5 text-end tabular-nums">{{ formatUsd(totalCost, locale) }}</td>
                    </tr>
                </tfoot>
            </table>
        </section>
    </div>
</template>
