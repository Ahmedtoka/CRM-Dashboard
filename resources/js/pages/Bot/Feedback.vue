<script setup lang="ts">
import BotTraceView from '@/components/crm/bot/BotTraceView.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import SectionTabs from '@/components/crm/SectionTabs.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatDateTime } from '@/lib/format';
import { sectionTabs } from '@/lib/sectionTabs';
import type { BotLayer, BotTrace, FeedbackReason } from '@/types/botBrain';
import { Head, Link, router } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { Flag, MessagesSquare } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

type Kind = 'knowledge' | 'instruction' | 'eval';

interface Item {
    id: number;
    reasons: FeedbackReason[];
    note: string | null;
    correct_reply: string | null;
    status: 'open' | 'converted' | 'dismissed';
    converted_to: Kind | null;
    suggestion_id: number | null;
    layer: BotLayer | null;
    tier: string | null;
    by: string | null;
    at: string | null;
    reply: string | null;
    trigger: string | null;
    model: string | null;
    conversation_id: number;
    message_id: number;
    trace_id: number | null;
}

const props = defineProps<{
    filters: { reason?: string; layer?: string; tier?: string; status: string; from?: string; to?: string };
    items: { data: Item[]; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null; total: number };
    counts: { open: number; last_30: number; by_reason: Record<FeedbackReason, number> };
    reasons: FeedbackReason[];
    layers: BotLayer[];
}>();

const { t, locale, dir } = useI18n();
const api = useApi();
const toast = useToast();
const phone = useMediaQuery('(max-width: 759px)');
const n = (v: number) => formatCount(v, locale.value);

function visit(patch: Record<string, string | undefined>): void {
    const query = { ...props.filters, ...patch };
    const clean = Object.fromEntries(Object.entries(query).filter(([, v]) => v !== undefined && v !== ''));
    router.get('/bot/feedback', clean, { preserveScroll: true, preserveState: true });
}

// Converting: one open form at a time.
const form = reactive({
    item: 0,
    kind: 'knowledge' as Kind,
    title: '',
    body: '',
    keywords: '',
    line: '',
    messages: '',
    handover: '' as '' | 'true' | 'false',
    says: '',
    never: '',
    busy: false,
    error: '',
});

function startConvert(item: Item, kind: Kind): void {
    Object.assign(form, {
        item: item.id,
        kind,
        title: '',
        body: item.correct_reply ?? '',
        keywords: '',
        line: '',
        messages: item.trigger ?? '',
        handover: item.reasons.includes('should_handover') ? 'true' : item.reasons.includes('needless_handover') ? 'false' : '',
        says: '',
        never: '',
        busy: false,
        error: '',
    });
}

const lines = (text: string) =>
    text
        .split('\n')
        .map((l) => l.trim())
        .filter(Boolean);

async function submitConvert(): Promise<void> {
    form.busy = true;
    form.error = '';
    const payload: Record<string, unknown> = { kind: form.kind };
    if (form.kind === 'knowledge')
        Object.assign(payload, {
            title: form.title,
            body: form.body,
            keywords: form.keywords
                .split(/[,،]/)
                .map((k) => k.trim())
                .filter(Boolean),
        });
    if (form.kind === 'instruction') payload.line = form.line;
    if (form.kind === 'eval') payload.messages = lines(form.messages).slice(0, 5);
    if (form.kind === 'eval')
        payload.expect = { handover: form.handover === '' ? null : form.handover === 'true', says: lines(form.says), never: lines(form.never) };
    try {
        await api.post(`/settings/bot-feedback/${form.item}/convert`, payload);
        toast.push(t('bot_brain.feedback.review.form.done'), 'success', { href: '/bot/learning', label: t('bot_brain.feedback.review.go_learning') });
        form.item = 0;
        router.reload({ only: ['items', 'counts'] });
    } catch (e) {
        form.error = apiErrorMessage(e, t('common.error'));
    } finally {
        form.busy = false;
    }
}

async function dismiss(item: Item): Promise<void> {
    try {
        await api.post(`/settings/bot-feedback/${item.id}/dismiss`);
        toast.push(t('bot_brain.feedback.review.dismissed'));
        router.reload({ only: ['items', 'counts'] });
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    }
}

// The trace of one item, in a sheet.
const traceOpen = ref(false);
const trace = ref<BotTrace | null>(null);
const traceLoading = ref(false);
const traceError = ref<string | null>(null);

async function openTrace(id: number): Promise<void> {
    traceOpen.value = true;
    traceLoading.value = true;
    traceError.value = null;
    trace.value = null;
    try {
        const { data } = await api.get<{ data: BotTrace }>(`/bot/feedback/traces/${id}`);
        trace.value = data.data;
    } catch (e) {
        traceError.value = apiErrorMessage(e, t('bot_brain.trace.failed'));
    } finally {
        traceLoading.value = false;
    }
}

const reasonCounts = computed(() => props.reasons.map((r) => ({ key: r, count: props.counts.by_reason[r] ?? 0 })));
const statuses = ['open', 'converted', 'dismissed'] as const;
const inputClass =
    'h-9 rounded-md border border-line-strong bg-card px-2.5 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
</script>

<template>
    <Head :title="t('bot_brain.feedback.review.title')" />

    <AppLayout
        :breadcrumbs="[
            { title: t('nav.bot'), href: '/bot' },
            { title: t('bot_brain.feedback.review.title'), href: '/bot/feedback' },
        ]"
    >
        <div class="mx-auto w-full max-w-6xl space-y-4 p-3 md:p-6">
            <PageHeader
                :title="t('bot_brain.feedback.review.title')"
                :description="t('bot_brain.feedback.review.hint')"
                :breadcrumbs="[
                    { label: t('nav.bot'), href: '/bot' },
                    { label: t('nav.bot_learning'), href: '/bot/learning' },
                    { label: t('bot_brain.feedback.review.title') },
                ]"
            />
            <SectionTabs :tabs="sectionTabs.botLearning(t, null, counts.open)" :label="t('nav.bot_learning')" />

            <section class="space-y-2 rounded-lg border border-line bg-card px-4 py-3" aria-labelledby="counts-title">
                <h2 id="counts-title" class="text-13 font-semibold text-fg-2">{{ t('bot_brain.feedback.review.counts_title') }}</h2>
                <ul class="flex flex-wrap gap-1.5">
                    <li v-for="r in reasonCounts" :key="r.key">
                        <button
                            type="button"
                            class="inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-13 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :class="
                                filters.reason === r.key
                                    ? 'border-brand bg-brand-soft font-semibold text-brand-ink'
                                    : 'border-line bg-card hover:bg-surface-2'
                            "
                            :aria-pressed="filters.reason === r.key"
                            :data-reason="r.key"
                            @click="visit({ reason: filters.reason === r.key ? undefined : r.key })"
                        >
                            {{ t(`bot_brain.feedback.reasons.${r.key}`) }}
                            <span class="tabular-nums text-fg-2">{{ n(r.count) }}</span>
                        </button>
                    </li>
                </ul>
            </section>

            <div class="flex flex-wrap items-end gap-2">
                <div class="inline-flex rounded-md bg-sunken p-0.5" role="group" :aria-label="t('bot_brain.feedback.review.title')">
                    <button
                        v-for="s in statuses"
                        :key="s"
                        type="button"
                        class="h-8 rounded px-3 text-13 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :class="filters.status === s ? 'bg-card font-semibold shadow-e1' : 'text-fg-2'"
                        :aria-pressed="filters.status === s"
                        @click="visit({ status: s })"
                    >
                        {{ t(`bot_brain.feedback.review.status.${s}`) }}
                    </button>
                </div>
                <label class="sr-only" for="fb-layer">{{ t('bot_brain.feedback.review.all_layers') }}</label>
                <select
                    id="fb-layer"
                    :class="inputClass"
                    :value="filters.layer ?? ''"
                    @change="visit({ layer: ($event.target as HTMLSelectElement).value || undefined })"
                >
                    <option value="">{{ t('bot_brain.feedback.review.all_layers') }}</option>
                    <option v-for="l in layers" :key="l" :value="l">{{ t(`bot_brain.brain.layers.${l}`) }}</option>
                </select>
                <label class="flex items-center gap-1.5 text-13 text-fg-2">
                    {{ t('bot_brain.feedback.review.from') }}
                    <input
                        type="date"
                        dir="ltr"
                        :class="inputClass"
                        :value="filters.from ?? ''"
                        @change="visit({ from: ($event.target as HTMLInputElement).value || undefined })"
                    />
                </label>
                <label class="flex items-center gap-1.5 text-13 text-fg-2">
                    {{ t('bot_brain.feedback.review.to') }}
                    <input
                        type="date"
                        dir="ltr"
                        :class="inputClass"
                        :value="filters.to ?? ''"
                        @change="visit({ to: ($event.target as HTMLInputElement).value || undefined })"
                    />
                </label>
            </div>

            <EmptyState
                v-if="!items.data.length"
                :icon="Flag"
                :title="t('bot_brain.feedback.review.empty')"
                :body="t('bot_brain.feedback.review.empty_hint')"
            />

            <ul v-else class="space-y-3">
                <li v-for="item in items.data" :key="item.id" class="rounded-lg border border-line bg-card" :data-feedback="item.id">
                    <div class="space-y-3 px-4 py-3">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <StatusChip v-for="r in item.reasons" :key="r" :label="t(`bot_brain.feedback.reasons.${r}`)" tone="danger" size="sm" />
                            <StatusChip v-if="item.layer" :label="t(`bot_brain.brain.layers.${item.layer}`)" tone="outline" size="sm" />
                            <StatusChip v-if="item.tier" :label="t(`bot_brain.trace.tier.${item.tier}`)" tone="outline" size="sm" />
                            <span class="text-12 ms-auto text-fg-3">
                                <template v-if="item.by">{{ t('bot_brain.feedback.review.by', { name: item.by }) }}، </template
                                >{{ formatDateTime(item.at, locale) }}
                            </span>
                        </div>
                        <div class="grid gap-3 md:grid-cols-2">
                            <div v-if="item.trigger" class="space-y-1">
                                <p class="text-12 font-semibold text-fg-2">{{ t('bot_brain.feedback.review.trigger') }}</p>
                                <p class="whitespace-pre-wrap rounded-md bg-[var(--bubble-in)] px-3 py-2 text-sm shadow-e1" dir="auto">
                                    {{ item.trigger }}
                                </p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-12 font-semibold text-fg-2">{{ t('bot_brain.feedback.review.reply') }}</p>
                                <p class="whitespace-pre-wrap rounded-md bg-[var(--bubble-bot)] px-3 py-2 text-sm shadow-e1" dir="auto">
                                    {{ item.reply }}
                                </p>
                            </div>
                        </div>
                        <p v-if="item.note" class="text-sm" dir="auto">{{ item.note }}</p>
                        <div v-if="item.correct_reply" class="space-y-1">
                            <p class="text-12 font-semibold text-fg-2">{{ t('bot_brain.feedback.review.correct') }}</p>
                            <p class="whitespace-pre-wrap rounded-md bg-ok-soft px-3 py-2 text-sm" dir="auto">{{ item.correct_reply }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <Button as-child variant="outline" size="sm">
                                <Link :href="`/inbox?c=${item.conversation_id}`"
                                    ><MessagesSquare class="size-4" aria-hidden="true" />{{ t('bot_brain.feedback.review.open_chat') }}</Link
                                >
                            </Button>
                            <Button v-if="item.trace_id" variant="outline" size="sm" @click="openTrace(item.trace_id)">{{
                                t('bot_brain.feedback.review.open_trace')
                            }}</Button>
                            <p v-if="item.status === 'converted' && item.converted_to" class="text-13 text-fg-2">
                                {{
                                    t('bot_brain.feedback.review.converted_to', { kind: t(`bot_brain.feedback.review.convert.${item.converted_to}`) })
                                }}
                                <Link href="/bot/learning" class="font-semibold text-brand hover:underline">{{
                                    t('bot_brain.feedback.review.go_learning')
                                }}</Link>
                            </p>
                        </div>
                    </div>

                    <div v-if="item.status === 'open'" class="space-y-3 border-t border-line bg-surface-2 px-4 py-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-13 font-semibold text-fg-2">{{ t('bot_brain.feedback.review.convert_label') }}</span>
                            <Button
                                v-for="k in ['knowledge', 'instruction', 'eval'] as const"
                                :key="k"
                                size="sm"
                                :variant="form.item === item.id && form.kind === k ? 'default' : 'outline'"
                                :data-convert="k"
                                @click="startConvert(item, k)"
                                >{{ t(`bot_brain.feedback.review.convert.${k}`) }}</Button
                            >
                            <Button variant="ghost" size="sm" class="ms-auto" @click="dismiss(item)">{{
                                t('bot_brain.feedback.review.dismiss')
                            }}</Button>
                        </div>

                        <form v-if="form.item === item.id" class="space-y-2.5" @submit.prevent="submitConvert">
                            <p class="text-12 text-fg-3">{{ t(`bot_brain.feedback.review.convert_hints.${form.kind}`) }}</p>
                            <template v-if="form.kind === 'knowledge'">
                                <label class="block space-y-1"
                                    ><span class="text-12 font-semibold text-fg-2">{{ t('bot_brain.feedback.review.form.title') }}</span
                                    ><input v-model="form.title" required maxlength="120" :class="[inputClass, 'w-full']" dir="auto"
                                /></label>
                                <label class="block space-y-1"
                                    ><span class="text-12 font-semibold text-fg-2">{{ t('bot_brain.feedback.review.form.body') }}</span
                                    ><textarea
                                        v-model="form.body"
                                        required
                                        rows="3"
                                        class="w-full rounded-md border border-line-strong bg-card px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        dir="auto"
                                    />
                                </label>
                                <label class="block space-y-1"
                                    ><span class="text-12 font-semibold text-fg-2">{{ t('bot_brain.feedback.review.form.keywords') }}</span
                                    ><input v-model="form.keywords" :class="[inputClass, 'w-full']" dir="auto"
                                /></label>
                            </template>
                            <label v-else-if="form.kind === 'instruction'" class="block space-y-1">
                                <span class="text-12 font-semibold text-fg-2">{{ t('bot_brain.feedback.review.form.line') }}</span>
                                <input v-model="form.line" required minlength="5" maxlength="400" :class="[inputClass, 'w-full']" dir="auto" />
                                <span class="text-12 text-fg-3">{{ t('bot_brain.feedback.review.form.line_hint') }}</span>
                            </label>
                            <template v-else>
                                <label class="block space-y-1">
                                    <span class="text-12 font-semibold text-fg-2">{{ t('bot_brain.feedback.review.form.messages') }}</span>
                                    <textarea
                                        v-model="form.messages"
                                        required
                                        rows="2"
                                        class="w-full rounded-md border border-line-strong bg-card px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        dir="auto"
                                    />
                                    <span class="text-12 text-fg-3">{{ t('bot_brain.feedback.review.form.messages_hint') }}</span>
                                </label>
                                <label class="flex flex-wrap items-center gap-2 text-13">
                                    <span class="font-semibold text-fg-2">{{ t('bot_brain.feedback.review.form.expect_handover') }}</span>
                                    <select v-model="form.handover" :class="inputClass">
                                        <option value="">{{ t('bot_brain.feedback.review.form.handover_any') }}</option>
                                        <option value="true">{{ t('bot_brain.feedback.review.form.handover_yes') }}</option>
                                        <option value="false">{{ t('bot_brain.feedback.review.form.handover_no') }}</option>
                                    </select>
                                </label>
                                <div class="grid gap-2 md:grid-cols-2">
                                    <label class="block space-y-1"
                                        ><span class="text-12 font-semibold text-fg-2">{{ t('bot_brain.feedback.review.form.says') }}</span
                                        ><textarea
                                            v-model="form.says"
                                            rows="2"
                                            class="w-full rounded-md border border-line-strong bg-card px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            dir="auto"
                                        />
                                    </label>
                                    <label class="block space-y-1"
                                        ><span class="text-12 font-semibold text-fg-2">{{ t('bot_brain.feedback.review.form.never') }}</span
                                        ><textarea
                                            v-model="form.never"
                                            rows="2"
                                            class="w-full rounded-md border border-line-strong bg-card px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            dir="auto"
                                        />
                                    </label>
                                </div>
                            </template>
                            <p v-if="form.error" class="text-13 text-danger" role="alert">{{ form.error }}</p>
                            <Button type="submit" size="sm" :disabled="form.busy">{{ t('bot_brain.feedback.review.form.submit') }}</Button>
                        </form>
                    </div>
                </li>
            </ul>

            <nav v-if="items.last_page > 1" class="flex items-center justify-between text-13" :aria-label="t('ui.pagination')">
                <Button as-child variant="outline" size="sm" :disabled="!items.prev_page_url"
                    ><Link v-if="items.prev_page_url" :href="items.prev_page_url" preserve-scroll>{{ t('ui.prev') }}</Link></Button
                >
                <span class="tabular-nums text-fg-2">{{ n(items.current_page) }} / {{ n(items.last_page) }}</span>
                <Button as-child variant="outline" size="sm" :disabled="!items.next_page_url"
                    ><Link v-if="items.next_page_url" :href="items.next_page_url" preserve-scroll>{{ t('ui.next') }}</Link></Button
                >
            </nav>

            <Sheet v-model:open="traceOpen">
                <SheetContent
                    :side="phone ? 'bottom' : dir === 'rtl' ? 'left' : 'right'"
                    class="flex w-full flex-col gap-0 p-0"
                    :class="phone ? 'max-h-[90dvh] rounded-t-xl' : 'sm:max-w-[440px]'"
                >
                    <SheetHeader class="border-b border-line px-5 pb-3.5 pt-4 text-start">
                        <SheetTitle class="text-lg font-bold">{{ t('bot_brain.trace.title') }}</SheetTitle>
                    </SheetHeader>
                    <div class="flex-1 overflow-y-auto px-5 py-4">
                        <div v-if="traceLoading" class="space-y-3"><Skeleton class="h-10 w-full" /><Skeleton class="h-24 w-full" /></div>
                        <EmptyState v-else-if="traceError" variant="error" :title="traceError" />
                        <BotTraceView v-else-if="trace" :trace="trace" />
                    </div>
                </SheetContent>
            </Sheet>
        </div>
    </AppLayout>
</template>
