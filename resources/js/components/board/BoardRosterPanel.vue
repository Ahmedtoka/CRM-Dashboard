<script setup lang="ts">
import BoardPanel from '@/components/board/BoardPanel.vue';
import BoardPlatforms from '@/components/board/BoardPlatforms.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useBoardContext } from '@/lib/board/context';
import { formatCount } from '@/lib/format';
import { LoaderCircle, UserPlus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

// The roster during the day: who sits at a desk on the open shift (a click opens her desk),
// and adding somebody to the open shift or to one of today's shifts still ahead.
defineProps<{ canManage: boolean }>();
const emit = defineEmits<{ close: []; member: [memberId: number] }>();

const { t, locale } = useI18n();
const board = useBoardContext();

/** The shifts somebody can still join: the open one first, then today's planned ones. */
const targets = computed(() =>
    board.shifts.value
        .filter((s) => s.status === 'open' || (s.status === 'planned' && s.date === board.businessDate.value))
        .sort((a, b) => Number(b.status === 'open') - Number(a.status === 'open')),
);

const shiftId = ref<number | null>(null);
const userId = ref<number | ''>('');

watch(
    targets,
    (list) => {
        if (!list.some((s) => s.id === shiftId.value)) shiftId.value = list[0]?.id ?? null;
    },
    { immediate: true },
);

const target = computed(() => targets.value.find((s) => s.id === shiftId.value) ?? null);
const seated = computed(() => new Set(target.value?.member_user_ids ?? []));
const candidates = computed(() => board.users.value.filter((u) => !seated.value.has(u.id)));

watch(shiftId, () => (userId.value = ''));

const desks = computed(() => board.members.value.filter((m) => m.user !== null));

async function add(): Promise<void> {
    if (shiftId.value === null || userId.value === '') return;
    if (await board.addMember(shiftId.value, userId.value, null)) userId.value = '';
}
</script>

<template>
    <BoardPanel :title="t('board.roster.title')" :subtitle="board.shift.value?.name ?? null" :error="board.error.value" @close="emit('close')">
        <div>
            <h3 class="mb-1 text-xs font-semibold text-muted-foreground">
                {{ t('board.roster.on_shift', { n: formatCount(desks.length, locale) }) }}
            </h3>
            <p v-if="desks.length === 0" class="text-xs text-muted-foreground">{{ t('board.roster.empty') }}</p>
            <ul v-else class="max-h-72 space-y-1 overflow-auto">
                <li v-for="m in desks" :key="m.id">
                    <button
                        type="button"
                        class="flex min-h-11 w-full items-center gap-2 rounded-md border border-border px-3 py-2 text-start hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        @click="emit('member', m.id)"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold text-foreground">{{ m.user?.name }}</span>
                            <span class="block text-2xs text-muted-foreground">
                                {{ m.is_leader ? `${t('board.leader.title')} · ` : '' }}{{ t(`board.roster.status.${m.status}`) }}
                            </span>
                        </span>
                        <BoardPlatforms :platforms="m.platforms ?? []" />
                        <span class="text-xs tabular-nums text-muted-foreground">
                            {{ formatCount(board.windowsOf(m.user?.id ?? 0).length, locale) }}/{{ formatCount(m.cap, locale) }}
                        </span>
                    </button>
                </li>
            </ul>
        </div>

        <form v-if="canManage" class="space-y-2 border-t border-border pt-3" @submit.prevent="add">
            <h3 class="text-xs font-semibold text-muted-foreground">{{ t('board.roster.add_title') }}</h3>
            <p v-if="targets.length === 0" class="text-xs text-muted-foreground">{{ t('board.roster.no_shift') }}</p>
            <template v-else>
                <div v-if="targets.length > 1">
                    <label class="mb-1 block text-xs font-semibold text-foreground" for="roster-shift">{{ t('board.roster.shift') }}</label>
                    <select
                        id="roster-shift"
                        v-model.number="shiftId"
                        class="h-10 w-full rounded-md border border-input bg-background px-2 text-sm text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <option v-for="s in targets" :key="s.id" :value="s.id">
                            {{
                                s.status === 'open'
                                    ? t('board.roster.shift_open', { name: s.name })
                                    : t('board.roster.shift_planned', { name: s.name })
                            }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-foreground" for="roster-user">{{ t('board.roster.user') }}</label>
                    <select
                        id="roster-user"
                        v-model="userId"
                        class="h-10 w-full rounded-md border border-input bg-background px-2 text-sm text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <option value="">{{ candidates.length === 0 ? t('board.roster.nobody_left') : t('board.roster.pick') }}</option>
                        <option v-for="u in candidates" :key="u.id" :value="u.id">
                            {{ u.online ? u.name : t('board.roster.offline_name', { name: u.name }) }}
                        </option>
                    </select>
                </div>
                <Button type="submit" class="w-full" :disabled="userId === '' || board.busy.value !== null">
                    <LoaderCircle v-if="board.busy.value === `add-${userId}`" class="animate-spin" aria-hidden="true" />
                    <UserPlus v-else aria-hidden="true" />
                    {{ t('board.roster.add') }}
                </Button>
                <p class="text-2xs text-muted-foreground">{{ t('board.roster.add_hint') }}</p>
            </template>
        </form>
    </BoardPanel>
</template>
