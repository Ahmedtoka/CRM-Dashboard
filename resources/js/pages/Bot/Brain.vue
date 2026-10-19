<script setup lang="ts">
import BotConsole from '@/components/crm/bot/BotConsole.vue';
import BotLayerLadder from '@/components/crm/bot/BotLayerLadder.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import SectionTabs from '@/components/crm/SectionTabs.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { translate } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatDateTime, formatUsd } from '@/lib/format';
import { sectionTabs } from '@/lib/sectionTabs';
import type { BotLayer } from '@/types/botBrain';
import { Head, Link } from '@inertiajs/vue3';
import { AlertTriangle, Copy, ExternalLink, Lock, PencilLine } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Brain {
    state: { bot_enabled: boolean; ai_enabled: boolean; agent_ready: boolean };
    router: {
        mode: 'legacy' | 'shadow' | 'hybrid';
        legacy_fallback: boolean;
        models: { planner: string; tier1: string; tier2: string; legacy: string };
        effort: { tier1: string; tier2: string; legacy: string };
        sonnet_cap: { cap: number; spent_today: number; hit: boolean };
        max_tasks: number;
    };
    tools: { name: string; description: string }[];
    handover: {
        keywords: string[];
        max_bot_turns: number;
        max_tasks: number;
        reasons: string[];
        escalations: string[];
        queue_on: boolean;
        hours_configured: boolean;
    };
    guards: string[];
    layers_7d: Record<BotLayer, number>;
    prompt?: {
        text: string;
        chars: number;
        approx_tokens: number;
        parts: {
            key: 'rules' | 'instructions' | 'knowledge';
            editable: boolean;
            href?: string;
            chars?: number;
            count?: number;
            updated_at?: string | null;
        }[];
        context_lines: string[];
    };
    sources?: { key: string; count: number; updated_at: string | null; href: string | null }[];
    pending?: { suggestions: number; feedback: number };
}

const props = defineProps<{ full: boolean; brain: Brain }>();

const { t, locale } = useI18n();
const n = (v: number) => formatCount(v, locale.value);
const reason = (r: string) => {
    const key = `reports.reasons.${r}`;
    const text = translate(locale.value, key);
    return text === key ? r : text;
};

const showPrompt = ref(false);
const copied = ref(false);

async function copyPrompt(): Promise<void> {
    await navigator.clipboard?.writeText(props.brain.prompt?.text ?? '');
    copied.value = true;
    window.setTimeout(() => (copied.value = false), 2000);
}

const warning = computed(() => {
    const s = props.brain.state;
    if (!s.bot_enabled) return t('bot_brain.brain.state.off');
    if (!s.ai_enabled) return t('bot_brain.brain.state.ai_off');
    if (!s.agent_ready) return t('bot_brain.brain.state.no_key');
    return null;
});

const models = computed(() => {
    const r = props.brain.router;
    const rows: { key: string; model: string; effort?: string }[] =
        r.mode === 'hybrid'
            ? [
                  { key: 'planner', model: r.models.planner },
                  { key: 'tier1', model: r.models.tier1, effort: r.effort.tier1 },
                  { key: 'tier2', model: r.models.tier2, effort: r.effort.tier2 },
              ]
            : [
                  { key: 'legacy', model: r.models.legacy, effort: r.effort.legacy },
                  ...(r.mode === 'shadow' ? [{ key: 'planner', model: r.models.planner }] : []),
              ];
    return rows;
});

const usd = (v: number) => formatUsd(v, locale.value);
</script>

<template>
    <Head :title="t('bot_brain.brain.title')" />

    <AppLayout
        :breadcrumbs="[
            { title: t('nav.bot'), href: '/bot' },
            { title: t('bot_brain.brain.title'), href: '/bot/brain' },
        ]"
    >
        <div class="mx-auto w-full max-w-6xl space-y-4 p-3 md:p-6">
            <PageHeader
                :title="t('bot_brain.brain.title')"
                :description="full ? t('bot_brain.brain.hint') : t('bot_brain.brain.summary_hint')"
                :breadcrumbs="[{ label: t('nav.bot'), href: full ? '/bot' : undefined }, { label: t('bot_brain.brain.title') }]"
            />
            <SectionTabs v-if="full" :tabs="sectionTabs.botBrain(t)" :label="t('bot_brain.nav.strip')" />

            <p v-if="warning" class="flex items-start gap-2 rounded-lg border border-warn/30 bg-warn-soft px-4 py-3 text-sm text-warn" role="status">
                <AlertTriangle class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{ warning }}
            </p>

            <!-- The memorable part: the order a reply is chosen in, with last week's real counts. -->
            <section class="space-y-2" aria-labelledby="ladder-title">
                <div>
                    <h2 id="ladder-title" class="text-base font-bold">{{ t('bot_brain.brain.ladder_title') }}</h2>
                    <p class="text-13 text-fg-2">{{ t('bot_brain.brain.ladder_hint') }}</p>
                </div>
                <BotLayerLadder :counts="brain.layers_7d" hints />
            </section>

            <div class="grid gap-4" :class="full && 'lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]'">
                <BotConsole v-if="full" />

                <div class="space-y-4">
                    <section class="rounded-lg border border-line bg-card" aria-labelledby="router-title">
                        <h2 id="router-title" class="border-b border-line px-4 py-3 text-base font-bold">{{ t('bot_brain.brain.router_title') }}</h2>
                        <div class="space-y-3 px-4 py-3 text-sm">
                            <p>
                                <span class="text-fg-2">{{ t('bot_brain.brain.mode_label') }}: </span>
                                <span class="font-semibold" data-testid="router-mode">{{ t(`bot_brain.brain.modes.${brain.router.mode}`) }}</span>
                            </p>
                            <dl class="divide-y divide-line rounded-md border border-line">
                                <div v-for="row in models" :key="row.key" class="flex items-center justify-between gap-3 px-3 py-2">
                                    <dt class="text-fg-2">{{ t(`bot_brain.brain.model_roles.${row.key}`) }}</dt>
                                    <dd class="text-end">
                                        <bdi class="font-semibold" dir="ltr">{{ row.model }}</bdi>
                                        <span v-if="row.effort" class="text-12 block text-fg-3">{{
                                            t('bot_brain.brain.effort', { level: row.effort })
                                        }}</span>
                                    </dd>
                                </div>
                            </dl>
                            <p v-if="brain.router.mode === 'hybrid'" class="text-13">
                                {{
                                    t('bot_brain.brain.cap_line', {
                                        spent: usd(brain.router.sonnet_cap.spent_today),
                                        cap: usd(brain.router.sonnet_cap.cap),
                                    })
                                }}
                                <StatusChip
                                    v-if="brain.router.sonnet_cap.hit"
                                    class="ms-1"
                                    :label="t('bot_brain.brain.cap_hit')"
                                    tone="warn"
                                    size="sm"
                                />
                            </p>
                            <p class="text-12 text-fg-3">{{ t('bot_brain.brain.how_to_switch') }}</p>
                            <div v-if="full" class="flex flex-wrap gap-2">
                                <Button as-child variant="outline" size="sm"
                                    ><Link href="/bot/guide">{{ t('bot_brain.brain.see_guide') }}</Link></Button
                                >
                                <Button as-child variant="outline" size="sm"
                                    ><Link href="/bot/costs">{{ t('bot_brain.brain.open_costs') }}</Link></Button
                                >
                            </div>
                        </div>
                    </section>

                    <section v-if="full && brain.pending" class="rounded-lg border border-line bg-card" aria-labelledby="pending-title">
                        <h2 id="pending-title" class="border-b border-line px-4 py-3 text-base font-bold">
                            {{ t('bot_brain.brain.pending_title') }}
                        </h2>
                        <ul class="divide-y divide-line text-sm">
                            <li v-if="brain.pending.suggestions" class="flex items-center justify-between px-4 py-2.5">
                                <span>{{ t('bot_brain.brain.pending_suggestions', { n: n(brain.pending.suggestions) }) }}</span>
                                <Link href="/bot/learning" class="font-semibold text-brand hover:underline">{{
                                    t('bot_brain.feedback.review.review_link')
                                }}</Link>
                            </li>
                            <li v-if="brain.pending.feedback" class="flex items-center justify-between px-4 py-2.5">
                                <span>{{ t('bot_brain.brain.pending_feedback', { n: n(brain.pending.feedback) }) }}</span>
                                <Link href="/bot/feedback" class="font-semibold text-brand hover:underline">{{
                                    t('bot_brain.feedback.review.review_link')
                                }}</Link>
                            </li>
                            <li v-if="!brain.pending.suggestions && !brain.pending.feedback" class="px-4 py-2.5 text-fg-3">
                                {{ t('bot_brain.brain.nothing_pending') }}
                            </li>
                        </ul>
                    </section>
                </div>
            </div>

            <section v-if="full && brain.prompt" class="rounded-lg border border-line bg-card" aria-labelledby="prompt-title">
                <header class="border-b border-line px-4 py-3">
                    <h2 id="prompt-title" class="text-base font-bold">{{ t('bot_brain.brain.prompt_title') }}</h2>
                    <p class="text-13 text-fg-2">{{ t('bot_brain.brain.prompt_hint') }}</p>
                </header>
                <ol class="divide-y divide-line text-sm">
                    <li
                        v-for="part in brain.prompt.parts"
                        :key="part.key"
                        class="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                        :data-part="part.key"
                    >
                        <div class="min-w-0">
                            <p class="font-semibold">{{ t(`bot_brain.brain.parts.${part.key}`) }}</p>
                            <p class="text-12 text-fg-3">
                                <template v-if="part.count !== undefined">{{ t('bot_brain.brain.entries', { n: n(part.count) }) }}</template>
                                <template v-else-if="part.chars !== undefined">{{ t('bot_brain.brain.chars', { n: n(part.chars) }) }}</template>
                                <template v-if="part.updated_at">
                                    · {{ t('bot_brain.brain.updated', { when: formatDateTime(part.updated_at, locale) }) }}</template
                                >
                            </p>
                        </div>
                        <Button v-if="part.editable && part.href" as-child variant="outline" size="sm">
                            <Link :href="part.href"><PencilLine class="size-4" aria-hidden="true" />{{ t('bot_brain.brain.edit') }}</Link>
                        </Button>
                        <span v-else class="text-12 inline-flex items-center gap-1 text-fg-3"
                            ><Lock class="size-3.5" aria-hidden="true" />{{ t('bot_brain.brain.part_fixed') }}</span
                        >
                    </li>
                </ol>
                <div class="space-y-3 border-t border-line px-4 py-3">
                    <p class="text-13 text-fg-2">{{ t('bot_brain.brain.context_title') }}</p>
                    <ul class="flex flex-wrap gap-1.5">
                        <li v-for="line in brain.prompt.context_lines" :key="line">
                            <StatusChip :label="t(`bot_brain.brain.context.${line}`)" tone="outline" />
                        </li>
                    </ul>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button variant="outline" size="sm" :aria-expanded="showPrompt" aria-controls="prompt-text" @click="showPrompt = !showPrompt">
                            {{ showPrompt ? t('bot_brain.brain.hide_full') : t('bot_brain.brain.show_full') }}
                        </Button>
                        <Button v-if="showPrompt" variant="ghost" size="sm" @click="copyPrompt"
                            ><Copy class="size-4" aria-hidden="true" />{{ copied ? t('bot_brain.brain.copied') : t('bot_brain.brain.copy') }}</Button
                        >
                        <span class="text-12 text-fg-3"
                            >{{ t('bot_brain.brain.chars', { n: n(brain.prompt.chars) }) }} ·
                            {{ t('bot_brain.brain.approx_tokens', { n: n(brain.prompt.approx_tokens) }) }}</span
                        >
                    </div>
                    <pre
                        v-if="showPrompt"
                        id="prompt-text"
                        class="max-h-[28rem] overflow-auto whitespace-pre-wrap break-words rounded-md bg-sunken p-3 text-13 leading-relaxed"
                        dir="auto"
                        data-testid="prompt-text"
                        >{{ brain.prompt.text }}</pre
                    >
                </div>
            </section>

            <div class="grid gap-4 lg:grid-cols-2">
                <section v-if="full && brain.sources" class="rounded-lg border border-line bg-card" aria-labelledby="sources-title">
                    <h2 id="sources-title" class="border-b border-line px-4 py-3 text-base font-bold">{{ t('bot_brain.brain.sources_title') }}</h2>
                    <ul class="divide-y divide-line text-sm">
                        <li v-for="s in brain.sources" :key="s.key" class="flex items-center justify-between gap-3 px-4 py-2.5" :data-source="s.key">
                            <div class="min-w-0">
                                <p class="font-semibold">{{ t(`bot_brain.brain.sources.${s.key}`) }}</p>
                                <p class="text-12 text-fg-3">
                                    {{
                                        s.updated_at
                                            ? t('bot_brain.brain.updated', { when: formatDateTime(s.updated_at, locale) })
                                            : t('bot_brain.brain.never')
                                    }}
                                </p>
                            </div>
                            <span class="flex items-center gap-2">
                                <span class="text-base font-bold tabular-nums">{{ n(s.count) }}</span>
                                <Link
                                    v-if="s.href"
                                    :href="s.href"
                                    class="text-fg-3 hover:text-foreground"
                                    :aria-label="t(`bot_brain.brain.sources.${s.key}`)"
                                    ><ExternalLink class="size-4 rtl:-scale-x-100" aria-hidden="true"
                                /></Link>
                            </span>
                        </li>
                    </ul>
                </section>

                <section class="rounded-lg border border-line bg-card" aria-labelledby="tools-title">
                    <header class="border-b border-line px-4 py-3">
                        <h2 id="tools-title" class="text-base font-bold">{{ t('bot_brain.brain.tools_title') }}</h2>
                        <p class="text-13 text-fg-2">{{ t('bot_brain.brain.tools_hint') }}</p>
                    </header>
                    <ul class="divide-y divide-line text-sm">
                        <li v-for="tool in brain.tools" :key="tool.name" class="px-4 py-2.5" :data-tool="tool.name">
                            <p class="font-semibold">
                                <bdi dir="ltr">{{ tool.name }}</bdi>
                            </p>
                            <p class="text-fg-2">{{ t(`bot_brain.brain.tools.${tool.name}`) }}</p>
                            <details v-if="full" class="text-12 mt-1 text-fg-3">
                                <summary class="cursor-pointer">{{ t('bot_brain.brain.tool_raw') }}</summary>
                                <p class="mt-1 leading-relaxed" dir="ltr">{{ tool.description }}</p>
                            </details>
                        </li>
                    </ul>
                </section>

                <section class="rounded-lg border border-line bg-card" aria-labelledby="handover-title">
                    <h2 id="handover-title" class="border-b border-line px-4 py-3 text-base font-bold">{{ t('bot_brain.brain.handover_title') }}</h2>
                    <div class="space-y-3 px-4 py-3 text-sm">
                        <p>{{ brain.handover.queue_on ? t('bot_brain.brain.handover_to_queue') : t('bot_brain.brain.handover_to_all') }}</p>
                        <p class="text-fg-2">{{ t('bot_brain.brain.urgent') }}</p>
                        <ul class="list-disc space-y-1 ps-5">
                            <li>
                                {{ t('bot_brain.brain.keywords') }}:
                                <span v-if="brain.handover.keywords.length" class="text-fg-2" dir="auto">{{
                                    brain.handover.keywords.join('، ')
                                }}</span>
                                <span v-else class="text-fg-3">{{ t('bot_brain.brain.no_keywords') }}</span>
                            </li>
                            <li>{{ t('bot_brain.brain.max_turns', { n: n(brain.handover.max_bot_turns) }) }}</li>
                            <li v-if="brain.router.mode === 'hybrid'">{{ t('bot_brain.brain.max_tasks', { n: n(brain.handover.max_tasks) }) }}</li>
                        </ul>
                        <p v-if="!brain.handover.hours_configured" class="text-13 text-warn">{{ t('bot_brain.brain.hours_missing') }}</p>
                        <div>
                            <h3 class="text-12 font-semibold text-fg-2">{{ t('bot_brain.brain.reasons_title') }}</h3>
                            <ul class="mt-1 flex flex-wrap gap-1.5">
                                <li v-for="r in brain.handover.reasons" :key="r"><StatusChip :label="reason(r)" tone="outline" size="sm" /></li>
                            </ul>
                        </div>
                        <div v-if="brain.router.mode === 'hybrid'">
                            <h3 class="text-12 font-semibold text-fg-2">{{ t('bot_brain.brain.escalations_title') }}</h3>
                            <ul class="mt-1 list-disc space-y-0.5 ps-5 text-13">
                                <li v-for="e in brain.handover.escalations" :key="e">{{ t(`bot_brain.brain.escalations.${e}`) }}</li>
                            </ul>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-line bg-card" aria-labelledby="guards-title">
                    <h2 id="guards-title" class="border-b border-line px-4 py-3 text-base font-bold">{{ t('bot_brain.brain.guards_title') }}</h2>
                    <div class="space-y-3 px-4 py-3 text-sm">
                        <ul class="space-y-2">
                            <li v-for="g in brain.guards" :key="g" class="flex gap-2">
                                <StatusChip :label="t(`bot_brain.trace.guard_names.${g}`)" tone="brand" size="sm" class="shrink-0" />
                                <span>{{ t(`bot_brain.brain.guards.${g}`) }}</span>
                            </li>
                        </ul>
                        <p class="text-13 text-fg-2">{{ t('bot_brain.brain.guards_fail') }}</p>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
