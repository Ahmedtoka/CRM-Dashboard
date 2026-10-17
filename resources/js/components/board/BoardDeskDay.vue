<script setup lang="ts">
import PointsLedger from '@/components/crm/queue/PointsLedger.vue';
import { Button } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { formatClock, formatCount, formatSeconds } from '@/lib/format';
import { QA_ITEMS, type DayWindow, type QaChecklist, type QueueDay } from '@/types/queue';
import { Link } from '@inertiajs/vue3';
import { ClipboardCheck, ExternalLink, LoaderCircle } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/**
 * Queue Part 2, the board's drill-in on one desk: her points of the day (with every ledger line
 * and its reason), the windows she closed today (each opens its conversation) and the leader's
 * quality check of any of them. Read from `GET /board/members/{id}/day` when the desk opens and
 * after every check; `refresh` re-reads it (the room's state does not carry it).
 */
const props = defineProps<{ memberId: number; pointsToday?: number }>();
const emit = defineEmits<{ updated: [] }>();

const { t, locale } = useI18n();
const api = useApi();

const day = ref<QueueDay | null>(null);
const loading = ref(false);
const failed = ref<string | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    failed.value = null;
    try {
        const { data } = await api.get<{ data: QueueDay }>(`/board/members/${props.memberId}/day`, { silent: true });
        day.value = data.data;
    } catch (error) {
        failed.value = apiErrorMessage(error, t('board.day.failed'));
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.memberId,
    () => {
        day.value = null;
        openQa.value = null;
        void load();
    },
    { immediate: true },
);

// A point awarded elsewhere (a rating, a case) moves her total on the desk: read the day again.
watch(
    () => props.pointsToday,
    (now, before) => {
        if (before !== undefined && now !== before) void load();
    },
);

// The quality check form of one window.
const openQa = ref<number | null>(null);
const score = ref(0);
const checklist = ref<QaChecklist>({});
const notes = ref('');
const saving = ref(false);
const qaError = ref<string | null>(null);

function startQa(w: DayWindow): void {
    if (openQa.value === w.id) {
        openQa.value = null;
        return;
    }
    openQa.value = w.id;
    score.value = w.qa?.score ?? 0;
    checklist.value = { ...(w.qa?.checklist ?? {}) };
    notes.value = w.qa?.notes ?? '';
    qaError.value = null;
}

async function saveQa(w: DayWindow): Promise<void> {
    if (score.value < 1) return;
    saving.value = true;
    qaError.value = null;
    try {
        const { data } = await api.post<{ data: QueueDay }>(`/board/entries/${w.id}/qa`, {
            score: score.value,
            checklist: Object.fromEntries(QA_ITEMS.map((k) => [k, Boolean(checklist.value[k])])),
            notes: notes.value,
        });
        day.value = data.data;
        openQa.value = null;
        emit('updated');
    } catch (error) {
        qaError.value = apiErrorMessage(error, t('board.day.qa_failed'));
    } finally {
        saving.value = false;
    }
}

const kinds = computed(() => {
    const byKind = day.value?.points.by_kind;
    if (!byKind) return [];

    return (Object.keys(byKind) as Array<keyof typeof byKind>).filter((k) => byKind[k] !== 0).map((k) => ({ key: k, value: byKind[k] }));
});

function signed(n: number): string {
    return `${n > 0 ? '+' : ''}${formatCount(n, locale.value)}`;
}
</script>

<template>
    <section class="space-y-3 border-t border-border pt-3" :aria-busy="loading">
        <div class="flex items-center justify-between gap-2">
            <h3 class="text-xs font-semibold text-muted-foreground">{{ t('board.day.points') }}</h3>
            <LoaderCircle v-if="loading" class="size-4 animate-spin text-muted-foreground" aria-hidden="true" />
        </div>
        <p v-if="failed" class="text-xs text-destructive" role="alert">{{ failed }}</p>

        <template v-if="day">
            <div class="flex flex-wrap items-baseline gap-2">
                <span class="text-2xl font-bold tabular-nums text-foreground">{{ formatCount(day.points.total, locale) }}</span>
                <span v-if="day.points.cap > 0" class="text-xs text-muted-foreground">{{
                    t('board.day.cap', { cap: formatCount(day.points.cap, locale) })
                }}</span>
                <span v-if="day.points.capped > 0" class="text-xs text-warning">{{
                    t('board.day.capped', { n: formatCount(day.points.capped, locale) })
                }}</span>
            </div>
            <ul v-if="kinds.length" class="flex flex-wrap gap-1 text-2xs">
                <li v-for="k in kinds" :key="k.key" class="rounded-full bg-muted px-2 py-0.5 tabular-nums">
                    {{ t(`queue_points.kinds.${k.key}`) }} {{ signed(k.value) }}
                </li>
            </ul>
            <PointsLedger :lines="day.points.ledger" compact />

            <div>
                <h3 class="mb-1 text-xs font-semibold text-muted-foreground">
                    {{ t('board.day.windows', { n: formatCount(day.windows.length, locale) }) }}
                </h3>
                <p v-if="day.windows.length === 0" class="text-xs text-muted-foreground">{{ t('board.day.no_windows') }}</p>
                <ul v-else class="space-y-1">
                    <li v-for="w in day.windows" :key="w.id" class="rounded-md border border-border px-2 py-1.5">
                        <div class="flex items-center gap-2">
                            <div class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-foreground">
                                    #{{ formatCount(w.ticket, locale) }} · {{ w.customer || t('queue.customer_fallback') }}
                                </span>
                                <span class="block text-2xs tabular-nums text-muted-foreground">
                                    {{ t(`board.day.reason.${w.close_reason ?? 'other'}`) }} · {{ formatClock(w.closed_at, locale) }}
                                    <template v-if="w.first_reply_seconds !== null">
                                        · {{ t('board.day.first_reply', { time: formatSeconds(w.first_reply_seconds, locale) }) }}</template
                                    >
                                    <template v-if="w.review_stars !== null">
                                        · {{ t('board.day.stars', { n: formatCount(w.review_stars, locale) }) }}</template
                                    >
                                    <template v-if="w.qa"> · {{ t('board.day.qa_score', { n: formatCount(w.qa.score, locale) }) }}</template>
                                </span>
                            </div>
                            <button
                                v-if="w.can_qa"
                                type="button"
                                class="grid size-9 shrink-0 place-items-center rounded-md text-primary hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                :class="openQa === w.id ? 'bg-muted' : ''"
                                :aria-label="t('board.day.qa_for', { ticket: formatCount(w.ticket, locale) })"
                                :title="t('board.day.qa')"
                                :aria-expanded="openQa === w.id"
                                @click="startQa(w)"
                            >
                                <ClipboardCheck class="size-4" aria-hidden="true" />
                            </button>
                            <Link
                                :href="`/inbox?c=${w.conversation_id}`"
                                class="grid size-9 shrink-0 place-items-center rounded-md text-primary hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                :aria-label="t('board.member.open_chat', { ticket: formatCount(w.ticket, locale) })"
                                :title="t('board.entry.open_chat')"
                            >
                                <ExternalLink class="size-4" aria-hidden="true" />
                            </Link>
                        </div>

                        <form v-if="openQa === w.id" class="mt-2 space-y-2 border-t border-border pt-2" @submit.prevent="saveQa(w)">
                            <fieldset>
                                <legend class="mb-1 text-xs font-semibold text-foreground">{{ t('board.day.qa_score_label') }}</legend>
                                <div class="flex gap-1">
                                    <button
                                        v-for="n in 5"
                                        :key="n"
                                        type="button"
                                        class="h-9 flex-1 rounded-md border text-sm font-semibold tabular-nums focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        :class="
                                            score === n
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : 'border-input bg-background text-foreground hover:bg-muted'
                                        "
                                        :aria-pressed="score === n"
                                        @click="score = n"
                                    >
                                        {{ formatCount(n, locale) }}
                                    </button>
                                </div>
                            </fieldset>
                            <div class="grid grid-cols-1 gap-1 text-xs sm:grid-cols-2">
                                <label v-for="item in QA_ITEMS" :key="item" class="flex items-center gap-2">
                                    <input v-model="checklist[item]" type="checkbox" class="size-4 rounded border-input" />
                                    {{ t(`board.day.checklist.${item}`) }}
                                </label>
                            </div>
                            <textarea
                                v-model="notes"
                                rows="2"
                                maxlength="1000"
                                class="w-full rounded-md border border-input bg-background px-2 py-1 text-sm text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                :placeholder="t('board.day.qa_notes')"
                                :aria-label="t('board.day.qa_notes')"
                            />
                            <p class="text-2xs text-muted-foreground">{{ t('board.day.qa_hint') }}</p>
                            <p v-if="qaError" class="text-xs text-destructive" role="alert">{{ qaError }}</p>
                            <Button type="submit" class="w-full" :disabled="score < 1 || saving" :loading="saving">{{
                                t('board.day.qa_save')
                            }}</Button>
                        </form>
                    </li>
                </ul>
            </div>
        </template>
    </section>
</template>
