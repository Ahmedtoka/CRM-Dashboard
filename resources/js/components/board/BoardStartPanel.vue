<script setup lang="ts">
import BoardPanel from '@/components/board/BoardPanel.vue';
import BoardPlatforms from '@/components/board/BoardPlatforms.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useI18n } from '@/composables/useI18n';
import { useBoardContext } from '@/lib/board/context';
import { formatCount, formatDay } from '@/lib/format';
import type { BoardTemplate } from '@/types/board';
import { LoaderCircle, Play } from 'lucide-vue-next';
import { computed, reactive, watch } from 'vue';

// «ابدأ اليوم»: no shift is open. Per shift template of today: who leads it and who sits at a
// desk. The shift that covers now (else the next one) opens at once; the others open on time
// with the moderators ticked here.
defineProps<{ canManage: boolean }>();

const { t, locale } = useI18n();
const board = useBoardContext();

const usable = computed(() => board.templates.value.filter((tpl) => tpl.status !== 'closed'));
const closed = computed(() => board.templates.value.filter((tpl) => tpl.status === 'closed'));
const activeIds = computed(() => new Set(board.users.value.map((u) => u.id)));

const roster = reactive<Record<string, number[]>>({});
const leaders = reactive<Record<string, number | null>>({});

/**
 * The ticks start from today's shift (when it was prepared already) and the roster remembered
 * from the last start. Seeded once per template: the state is re-read every few seconds and
 * must not undo what she ticked meanwhile.
 */
function seed(): void {
    for (const tpl of board.templates.value) {
        if (tpl.key in roster) continue;

        const prepared = board.shifts.value.find((s) => s.shift_key === tpl.key && s.status !== 'closed')?.member_user_ids ?? [];
        const remembered = board.defaultRoster.value[tpl.key] ?? [];
        roster[tpl.key] = [...new Set([...prepared, ...remembered])].filter((id) => activeIds.value.has(id));
        leaders[tpl.key] = tpl.leader_user_id !== null && activeIds.value.has(tpl.leader_user_id) ? tpl.leader_user_id : null;
    }
}

watch(() => board.templates.value, seed, { immediate: true });

function ticked(key: string, userId: number): boolean {
    return leaders[key] === userId || (roster[key] ?? []).includes(userId);
}

function toggle(key: string, userId: number, on: boolean): void {
    const now = (roster[key] ?? []).filter((id) => id !== userId);
    roster[key] = on ? [...now, userId] : now;
}

function setLeader(key: string, value: string): void {
    const id = Number(value);
    leaders[key] = value === '' || Number.isNaN(id) ? null : id;
}

/** Everybody who gets a desk today, once each. */
const seats = computed(() => {
    const ids = new Set<number>();
    for (const tpl of usable.value) {
        (roster[tpl.key] ?? []).forEach((id) => ids.add(id));
        if (leaders[tpl.key] !== null && leaders[tpl.key] !== undefined) ids.add(leaders[tpl.key] as number);
    }

    return ids.size;
});

function countOf(tpl: BoardTemplate): number {
    const ids = new Set(roster[tpl.key] ?? []);
    if (leaders[tpl.key] !== null && leaders[tpl.key] !== undefined) ids.add(leaders[tpl.key] as number);

    return ids.size;
}

function location(value: string): string {
    return value === 'office' || value === 'home' ? t(`board.start.location.${value}`) : value;
}

async function start(): Promise<void> {
    if (seats.value === 0) return;

    await board.startDay(
        Object.fromEntries(usable.value.map((tpl) => [tpl.key, (roster[tpl.key] ?? []).filter((id) => activeIds.value.has(id))])),
        Object.fromEntries(usable.value.map((tpl) => [tpl.key, leaders[tpl.key] ?? null])),
    );
}
</script>

<template>
    <BoardPanel
        class="w-full max-w-3xl"
        :title="t('board.start.title')"
        :subtitle="board.businessDate.value ? t('board.start.subtitle', { date: formatDay(`${board.businessDate.value}T12:00:00Z`, locale) }) : null"
        :error="board.error.value"
        :closable="false"
    >
        <p v-if="!canManage" class="text-muted-foreground">{{ t('board.start.read_only') }}</p>

        <template v-else>
            <p class="text-xs text-muted-foreground">{{ t('board.start.intro') }}</p>

            <p v-if="usable.length === 0" class="rounded-md bg-muted px-3 py-2 text-sm text-foreground">{{ t('board.start.all_closed') }}</p>

            <div class="grid gap-3 md:grid-cols-2">
                <fieldset v-for="tpl in usable" :key="tpl.key" class="min-w-0 rounded-lg border border-border p-3">
                    <legend class="px-1 text-sm font-bold text-foreground">
                        {{ tpl.name }}
                        <span class="ms-1 font-normal tabular-nums text-muted-foreground" dir="ltr">{{ tpl.from }}–{{ tpl.to }}</span>
                    </legend>

                    <div class="mb-2 flex flex-wrap items-center gap-1 text-2xs">
                        <span v-if="tpl.opens_now" class="rounded-full bg-primary px-2 py-0.5 font-semibold text-primary-foreground">{{
                            t('board.start.opens_now')
                        }}</span>
                        <span v-else class="rounded-full bg-muted px-2 py-0.5 text-muted-foreground">{{
                            t('board.start.opens_at', { time: tpl.from })
                        }}</span>
                        <span class="rounded-full bg-muted px-2 py-0.5 text-muted-foreground">{{ location(tpl.location) }}</span>
                        <span class="ms-auto tabular-nums text-muted-foreground">{{
                            t('board.start.count', { n: formatCount(countOf(tpl), locale) })
                        }}</span>
                    </div>

                    <label class="mb-1 block text-xs font-semibold text-foreground" :for="`leader-${tpl.key}`">{{ t('board.start.leader') }}</label>
                    <select
                        :id="`leader-${tpl.key}`"
                        class="mb-3 h-10 w-full rounded-md border border-input bg-background px-2 text-sm text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :value="leaders[tpl.key] ?? ''"
                        @change="setLeader(tpl.key, ($event.target as HTMLSelectElement).value)"
                    >
                        <option value="">{{ t('board.start.no_leader') }}</option>
                        <option v-for="u in board.users.value" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>

                    <p class="mb-1 text-xs font-semibold text-foreground">{{ t('board.start.moderators') }}</p>
                    <p v-if="board.users.value.length === 0" class="text-xs text-muted-foreground">{{ t('board.start.no_users') }}</p>
                    <ul v-else class="max-h-64 space-y-0.5 overflow-auto pe-1">
                        <li v-for="u in board.users.value" :key="u.id">
                            <label
                                class="flex min-h-10 cursor-pointer items-center gap-2 rounded-md px-2 py-1 hover:bg-muted"
                                :for="`seat-${tpl.key}-${u.id}`"
                            >
                                <Checkbox
                                    :id="`seat-${tpl.key}-${u.id}`"
                                    :checked="ticked(tpl.key, u.id)"
                                    :disabled="leaders[tpl.key] === u.id"
                                    @update:checked="(on: boolean | 'indeterminate') => toggle(tpl.key, u.id, on === true)"
                                />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm text-foreground">{{ u.name }}</span>
                                    <span class="block text-2xs text-muted-foreground">
                                        {{ leaders[tpl.key] === u.id ? t('board.start.leader_desk') : u.role ? t(`roles.${u.role}`) : '' }}
                                    </span>
                                </span>
                                <span
                                    class="size-2 shrink-0 rounded-full"
                                    :class="u.online ? 'bg-emerald-500' : 'bg-muted-foreground/40'"
                                    role="img"
                                    :aria-label="u.online ? t('board.start.online') : t('board.start.offline')"
                                    :title="u.online ? t('board.start.online') : t('board.start.offline')"
                                />
                                <BoardPlatforms :platforms="u.platforms" />
                            </label>
                        </li>
                    </ul>
                </fieldset>
            </div>

            <p v-if="closed.length > 0" class="text-2xs text-muted-foreground">
                {{ t('board.start.closed_today', { list: closed.map((tpl) => tpl.name).join(t('board.platforms.separator')) }) }}
            </p>

            <div class="flex flex-wrap items-center gap-3 border-t border-border pt-3">
                <p class="flex-1 text-xs text-muted-foreground">
                    {{ seats === 0 ? t('board.start.pick_someone') : t('board.start.summary', { n: formatCount(seats, locale) }) }}
                </p>
                <Button :disabled="seats === 0 || usable.length === 0 || board.busy.value !== null" @click="start">
                    <LoaderCircle v-if="board.busy.value === 'start'" class="animate-spin" aria-hidden="true" />
                    <Play v-else aria-hidden="true" />
                    {{ t('board.start.button') }}
                </Button>
            </div>
        </template>
    </BoardPanel>
</template>
