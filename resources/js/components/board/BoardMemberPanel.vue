<script setup lang="ts">
import BoardPanel from '@/components/board/BoardPanel.vue';
import BoardPlatforms from '@/components/board/BoardPlatforms.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useBoardContext } from '@/lib/board/context';
import { formatClock, formatCount, formatSeconds } from '@/lib/format';
import { Link } from '@inertiajs/vue3';
import { Coffee, ExternalLink, LoaderCircle, UserMinus, UserRoundCheck } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

// A desk: her day so far, her open windows (each a link to the conversation), her break, her
// number of windows, and taking her out of the shift. Part 2 adds points, reviews and QA.
const props = defineProps<{ memberId: number; canManage: boolean }>();
const emit = defineEmits<{ close: []; entry: [entryId: number] }>();

const { t, locale } = useI18n();
const board = useBoardContext();

const member = computed(() => board.members.value.find((m) => m.id === props.memberId) ?? null);
const windows = computed(() => (member.value?.user ? board.windowsOf(member.value.user.id) : []));

const statusText = computed(() => {
    const m = member.value;
    if (m === null) return '';
    if (m.status === 'break') {
        const left = board.breakLeft(m);

        return left === null ? t('board.status.break') : t('board.status.break_left', { time: formatSeconds(left, locale.value) });
    }
    if (m.status === 'available' || m.status === 'busy') {
        return windows.value.length > 0
            ? t('board.status.busy', { n: formatCount(windows.value.length, locale.value) })
            : t('board.status.available');
    }

    return t(`board.status.${m.status}`);
});

const counters = computed(() => {
    const today = member.value?.today ?? {};

    return (['received', 'inquiry', 'problem', 'case', 'auto', 'escalation'] as const).map((key) => ({
        key,
        label: t(`board.member.today.${key}`),
        value: formatCount(today[key] ?? 0, locale.value),
    }));
});

const onBreak = computed(() => member.value?.status === 'break' || member.value?.status === 'pending_break');
const breakAt = computed(() => (member.value?.break_at ? formatClock(member.value.break_at, locale.value) : null));

const cap = ref(1);
const removing = ref(false);

watch(
    () => [props.memberId, member.value?.cap] as const,
    ([, now]) => {
        cap.value = now ?? board.settings.value?.windows_per_moderator ?? 1;
    },
    { immediate: true },
);
watch(
    () => props.memberId,
    () => {
        removing.value = false;
        board.clearError();
    },
);

async function toggleBreak(): Promise<void> {
    if (member.value === null) return;
    await board.setMemberStatus(member.value.id, onBreak.value ? 'available' : 'break');
}

async function saveCap(): Promise<void> {
    const m = member.value;
    if (m === null || m.user === null || cap.value === m.cap) return;
    await board.addMember(m.shift_id, m.user.id, cap.value);
}

async function remove(): Promise<void> {
    if (member.value === null) return;
    if (await board.removeMember(member.value.id)) emit('close');
}
</script>

<template>
    <BoardPanel
        :title="member?.user?.name ?? t('board.member.gone_title')"
        :subtitle="member ? (member.is_leader ? `${t('board.leader.title')} · ${statusText}` : statusText) : null"
        :error="board.error.value"
        @close="$emit('close')"
    >
        <p v-if="!member" class="text-muted-foreground">{{ t('board.member.gone') }}</p>

        <template v-else>
            <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <BoardPlatforms :platforms="member.platforms ?? []" />
                <span v-if="member.online === false" class="rounded-full bg-muted px-2 py-0.5">{{ t('board.status.offline') }}</span>
                <span v-if="breakAt && !onBreak" class="tabular-nums">{{ t('board.member.break_at', { time: breakAt }) }}</span>
            </div>

            <dl class="grid grid-cols-3 gap-2 text-xs">
                <div v-for="c in counters" :key="c.key" class="rounded-md bg-muted px-2 py-2 text-center">
                    <dt class="truncate text-muted-foreground">{{ c.label }}</dt>
                    <dd class="text-base font-bold tabular-nums text-foreground">{{ c.value }}</dd>
                </div>
            </dl>

            <div>
                <h3 class="mb-1 text-xs font-semibold text-muted-foreground">
                    {{ t('board.member.windows', { open: formatCount(windows.length, locale), cap: formatCount(member.cap, locale) }) }}
                </h3>
                <p v-if="windows.length === 0" class="text-xs text-muted-foreground">{{ t('board.member.no_windows') }}</p>
                <ul v-else class="space-y-1">
                    <li v-for="e in windows" :key="e.id" class="flex items-center gap-2 rounded-md border border-border px-2 py-1.5">
                        <button
                            type="button"
                            class="min-w-0 flex-1 rounded text-start focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            @click="emit('entry', e.id)"
                        >
                            <span class="block truncate text-sm font-semibold text-foreground">
                                {{ t('queue.window', { n: formatCount(e.window_no ?? 0, locale) }) }} · #{{
                                    formatCount(e.ticket % 100000, locale)
                                }}
                                ·
                                {{ e.customer?.name || t('queue.customer_fallback') }}
                            </span>
                            <span class="block text-2xs tabular-nums text-muted-foreground">
                                {{ t('board.member.since', { time: formatSeconds(board.secondsSince(e.delivered_at), locale) }) }}
                                <template v-if="board.silenceLeft(e) !== null">
                                    · {{ t('board.member.silence', { time: formatSeconds(board.silenceLeft(e), locale) }) }}</template
                                >
                                <template v-else-if="e.first_reply_at === null"> · {{ t('board.entry.no_reply_yet') }}</template>
                            </span>
                        </button>
                        <Link
                            :href="`/inbox?c=${e.conversation_id}`"
                            class="grid size-9 shrink-0 place-items-center rounded-md text-primary hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :aria-label="t('board.member.open_chat', { ticket: formatCount(e.ticket % 100000, locale) })"
                            :title="t('board.entry.open_chat')"
                        >
                            <ExternalLink class="size-4" aria-hidden="true" />
                        </Link>
                    </li>
                </ul>
            </div>

            <template v-if="canManage">
                <div class="space-y-1 border-t border-border pt-3">
                    <Button variant="outline" class="w-full" :disabled="board.busy.value !== null" @click="toggleBreak">
                        <LoaderCircle v-if="board.busy.value === `status-${member.id}`" class="animate-spin" aria-hidden="true" />
                        <UserRoundCheck v-else-if="onBreak" aria-hidden="true" />
                        <Coffee v-else aria-hidden="true" />
                        {{ onBreak ? t('board.member.back') : t('board.member.break') }}
                    </Button>
                    <p class="text-2xs text-muted-foreground">
                        {{
                            onBreak
                                ? t('board.member.back_hint')
                                : windows.length > 0
                                  ? t('board.member.break_pending_hint')
                                  : t('board.member.break_hint', { n: formatCount(board.settings.value?.break_minutes ?? 30, locale) })
                        }}
                    </p>
                </div>

                <form class="flex items-end gap-2" @submit.prevent="saveCap">
                    <div class="flex-1">
                        <label class="mb-1 block text-xs font-semibold text-foreground" :for="`cap-${member.id}`">{{ t('board.member.cap') }}</label>
                        <select
                            :id="`cap-${member.id}`"
                            v-model.number="cap"
                            class="h-10 w-full rounded-md border border-input bg-background px-2 text-sm text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <option v-for="n in 10" :key="n" :value="n">{{ formatCount(n, locale) }}</option>
                        </select>
                    </div>
                    <Button type="submit" variant="outline" :disabled="cap === member.cap || board.busy.value !== null">
                        <LoaderCircle v-if="board.busy.value === `add-${member.user?.id}`" class="animate-spin" aria-hidden="true" />
                        {{ t('board.member.cap_save') }}
                    </Button>
                </form>

                <div class="border-t border-border pt-3">
                    <Button v-if="!removing" variant="ghost" class="w-full text-destructive hover:text-destructive" @click="removing = true">
                        <UserMinus aria-hidden="true" />
                        {{ t('board.member.remove') }}
                    </Button>
                    <div v-else class="space-y-2" role="alertdialog" :aria-label="t('board.member.remove')">
                        <p class="text-xs text-foreground">
                            {{
                                windows.length > 0
                                    ? t('board.member.remove_hint_windows', { n: formatCount(windows.length, locale) })
                                    : t('board.member.remove_hint')
                            }}
                        </p>
                        <div class="flex gap-2">
                            <Button variant="destructive" class="flex-1" :disabled="board.busy.value !== null" @click="remove">
                                <LoaderCircle v-if="board.busy.value === `remove-${member.id}`" class="animate-spin" aria-hidden="true" />
                                {{ t('board.member.remove_confirm') }}
                            </Button>
                            <Button variant="outline" @click="removing = false">{{ t('board.cancel.back') }}</Button>
                        </div>
                    </div>
                </div>
            </template>
        </template>
    </BoardPanel>
</template>
