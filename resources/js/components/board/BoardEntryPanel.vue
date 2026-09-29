<script setup lang="ts">
import BoardPanel from '@/components/board/BoardPanel.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useBoardContext } from '@/lib/board/context';
import { formatCount, formatSeconds } from '@/lib/format';
import type { BoardMember } from '@/types/board';
import { Link } from '@inertiajs/vue3';
import { ExternalLink, LoaderCircle } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ entryId: number; canManage: boolean }>();
const emit = defineEmits<{ close: []; member: [memberId: number] }>();

const { t, locale } = useI18n();
const board = useBoardContext();

const entry = computed(() => [...board.waiting.value, ...board.open.value].find((e) => e.id === props.entryId) ?? null);
const waiting = computed(() => entry.value?.status === 'waiting');
const ticket = computed(() => (entry.value ? entry.value.ticket % 100000 : 0));
const name = computed(() => entry.value?.customer?.name || t('queue.customer_fallback'));
const holder = computed(() =>
    entry.value?.assigned_user_id ? (board.members.value.find((m) => m.user?.id === entry.value?.assigned_user_id) ?? null) : null,
);
const holderName = computed(() => holder.value?.user?.name ?? board.userName(entry.value?.assigned_user_id) ?? '');
const reservedName = computed(() => board.userName(entry.value?.reserved_user_id));

/** What the bot understood, line by line. Customer words: always written as text. */
const summary = computed(() => {
    const raw = entry.value?.bot_summary?.lines;
    const lines = Array.isArray(raw) ? raw.filter((l): l is string => typeof l === 'string' && l.trim() !== '') : [];
    const first = entry.value?.request_line ?? null;

    return { first, lines: lines.filter((l) => l.trim() !== first).slice(0, 6) };
});

const silence = computed(() => (entry.value ? board.silenceLeft(entry.value) : null));
const handoff = computed(() => (entry.value ? board.handoffLeft(entry.value) : null));

/** Why a desk cannot take her now; null when it can. */
function refusal(member: BoardMember): string | null {
    const e = entry.value;
    if (e === null || member.user === null) return t('board.assign.unavailable');
    if (!['available', 'busy'].includes(member.status)) return t(`board.status.${member.status}`);
    if (e.platform !== null && member.platforms !== undefined && !member.platforms.includes(e.platform)) return t('board.assign.platform');
    if (board.windowsOf(member.user.id).length >= member.cap) return t('board.assign.full');

    return null;
}

const desks = computed(() =>
    board.members.value
        .filter((m) => m.user !== null)
        .map((m) => ({ member: m, open: board.windowsOf(m.user?.id ?? 0).length, refusal: refusal(m) }))
        .sort((a, b) => Number(a.refusal !== null) - Number(b.refusal !== null) || a.open - b.open),
);

const REASONS = ['duplicate', 'mistake', 'test', 'served'] as const;
const cancelling = ref(false);
const reason = ref('');

watch(
    () => props.entryId,
    () => {
        cancelling.value = false;
        reason.value = '';
        board.clearError();
    },
);

async function assign(member: BoardMember): Promise<void> {
    if (member.user === null) return;
    await board.assign(props.entryId, member.user.id);
}

async function cancel(): Promise<void> {
    if (reason.value.trim().length < 2) return;
    if (await board.cancel(props.entryId, reason.value.trim())) emit('close');
}
</script>

<template>
    <BoardPanel
        :title="entry ? t('board.entry.title', { ticket: formatCount(ticket, locale), name }) : t('board.entry.gone_title')"
        :subtitle="entry?.platform ? t(`board.platforms.${entry.platform}`) : null"
        :error="board.error.value"
        @close="$emit('close')"
    >
        <p v-if="!entry" class="text-muted-foreground">{{ t('board.entry.gone') }}</p>

        <template v-else>
            <dl class="grid grid-cols-2 gap-2 text-xs">
                <div class="rounded-md bg-muted px-3 py-2">
                    <dt class="text-muted-foreground">{{ t('board.entry.status') }}</dt>
                    <dd class="font-semibold text-foreground">
                        {{
                            waiting
                                ? t('board.entry.in_lounge')
                                : t('board.entry.at_window', { n: formatCount(entry.window_no ?? 0, locale), name: holderName })
                        }}
                    </dd>
                </div>
                <div class="rounded-md bg-muted px-3 py-2">
                    <dt class="text-muted-foreground">{{ waiting ? t('board.entry.waiting_since') : t('board.entry.chat_time') }}</dt>
                    <dd class="font-semibold tabular-nums text-foreground">
                        {{ formatSeconds(board.secondsSince(waiting ? entry.enqueued_at : entry.delivered_at), locale) }}
                    </dd>
                </div>
                <div v-if="entry.priority !== 'live'" class="rounded-md bg-muted px-3 py-2">
                    <dt class="text-muted-foreground">{{ t('board.entry.priority') }}</dt>
                    <dd class="font-semibold text-foreground">{{ t(`board.priority.${entry.priority}`) }}</dd>
                </div>
                <div v-if="waiting && reservedName" class="rounded-md bg-muted px-3 py-2">
                    <dt class="text-muted-foreground">{{ t('board.entry.reserved') }}</dt>
                    <dd class="truncate font-semibold text-foreground">{{ reservedName }}</dd>
                </div>
                <div v-if="!waiting" class="rounded-md bg-muted px-3 py-2">
                    <dt class="text-muted-foreground">{{ t('board.entry.silence') }}</dt>
                    <dd
                        class="font-semibold tabular-nums"
                        :class="silence !== null && board.silenceTone(entry) !== 'calm' ? 'text-destructive' : 'text-foreground'"
                    >
                        {{
                            silence === null
                                ? entry.first_reply_at === null
                                    ? t('board.entry.no_reply_yet')
                                    : t('board.entry.customer_wrote')
                                : formatSeconds(silence, locale)
                        }}
                    </dd>
                </div>
                <div v-if="!waiting && entry.reply_overdue" class="rounded-md bg-muted px-3 py-2">
                    <dt class="text-muted-foreground">{{ t('board.entry.reply_overdue') }}</dt>
                    <dd class="font-semibold tabular-nums text-orange-600 dark:text-orange-400">
                        {{ handoff === null ? t('board.entry.no_handoff') : t('board.entry.handoff', { time: formatSeconds(handoff, locale) }) }}
                    </dd>
                </div>
            </dl>

            <div v-if="summary.first || summary.lines.length > 0">
                <h3 class="mb-1 text-xs font-semibold text-muted-foreground">{{ t('board.entry.request') }}</h3>
                <p v-if="summary.first" class="break-words font-semibold text-foreground">{{ summary.first }}</p>
                <ul v-if="summary.lines.length > 0" class="mt-1 list-inside list-disc space-y-0.5 break-words text-xs text-muted-foreground">
                    <li v-for="(line, i) in summary.lines" :key="i">{{ line }}</li>
                </ul>
            </div>

            <Button as-child variant="outline" class="w-full">
                <Link :href="`/inbox?c=${entry.conversation_id}`">
                    <ExternalLink aria-hidden="true" />
                    {{ t('board.entry.open_chat') }}
                </Link>
            </Button>

            <button
                v-if="holder && !waiting"
                type="button"
                class="w-full rounded-md px-3 py-2 text-start text-xs font-semibold text-primary hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                @click="emit('member', holder.id)"
            >
                {{ t('board.entry.see_desk', { name: holderName }) }}
            </button>

            <template v-if="waiting && canManage">
                <div>
                    <h3 class="mb-1 text-xs font-semibold text-muted-foreground">{{ t('board.assign.title') }}</h3>
                    <p v-if="desks.length === 0" class="text-xs text-muted-foreground">{{ t('board.assign.nobody') }}</p>
                    <ul v-else class="max-h-56 space-y-1 overflow-auto">
                        <li v-for="desk in desks" :key="desk.member.id">
                            <button
                                type="button"
                                class="flex min-h-11 w-full items-center gap-2 rounded-md border border-border px-3 py-2 text-start hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent"
                                :disabled="desk.refusal !== null || board.busy.value !== null"
                                @click="assign(desk.member)"
                            >
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-semibold text-foreground">{{ desk.member.user?.name }}</span>
                                    <span class="block text-2xs text-muted-foreground">{{
                                        desk.refusal ?? t('board.assign.free', { n: formatCount(desk.member.cap - desk.open, locale) })
                                    }}</span>
                                </span>
                                <LoaderCircle v-if="board.busy.value === `assign-${entryId}`" class="size-4 animate-spin" aria-hidden="true" />
                                <span v-else class="text-xs tabular-nums text-muted-foreground"
                                    >{{ formatCount(desk.open, locale) }}/{{ formatCount(desk.member.cap, locale) }}</span
                                >
                            </button>
                        </li>
                    </ul>
                </div>

                <div class="border-t border-border pt-3">
                    <Button v-if="!cancelling" variant="ghost" class="w-full text-destructive hover:text-destructive" @click="cancelling = true">
                        {{ t('board.cancel.button') }}
                    </Button>
                    <form v-else class="space-y-2" @submit.prevent="cancel">
                        <label class="block text-xs font-semibold text-foreground" :for="`cancel-reason-${entryId}`">{{
                            t('board.cancel.reason')
                        }}</label>
                        <div class="flex flex-wrap gap-1">
                            <button
                                v-for="key in REASONS"
                                :key="key"
                                type="button"
                                class="min-h-8 rounded-full border border-border px-3 text-xs hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                @click="reason = t(`board.cancel.reasons.${key}`)"
                            >
                                {{ t(`board.cancel.reasons.${key}`) }}
                            </button>
                        </div>
                        <textarea
                            :id="`cancel-reason-${entryId}`"
                            v-model="reason"
                            rows="2"
                            maxlength="200"
                            required
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            :placeholder="t('board.cancel.placeholder')"
                        />
                        <p class="text-2xs text-muted-foreground">{{ t('board.cancel.hint') }}</p>
                        <div class="flex gap-2">
                            <Button
                                type="submit"
                                variant="destructive"
                                class="flex-1"
                                :disabled="reason.trim().length < 2 || board.busy.value !== null"
                            >
                                <LoaderCircle v-if="board.busy.value === `cancel-${entryId}`" class="animate-spin" aria-hidden="true" />
                                {{ t('board.cancel.confirm') }}
                            </Button>
                            <Button type="button" variant="outline" @click="cancelling = false">{{ t('board.cancel.back') }}</Button>
                        </div>
                    </form>
                </div>
            </template>
        </template>
    </BoardPanel>
</template>
