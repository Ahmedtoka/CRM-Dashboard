<script setup lang="ts">
import BoardPanel from '@/components/board/BoardPanel.vue';
import BoardPlatforms from '@/components/board/BoardPlatforms.vue';
import { useI18n } from '@/composables/useI18n';
import { useBoardContext } from '@/lib/board/context';
import { formatCount } from '@/lib/format';
import { computed } from 'vue';

// The team of the open shift: who is checked in (a click opens her desk, where the leader can
// send her on a break or check her out on her behalf). Nobody is added from here: moderators
// check themselves in from the inbox (attendance design 2026-09-29).
defineProps<{ canManage: boolean }>();
const emit = defineEmits<{ close: []; member: [memberId: number] }>();

const { t, locale } = useI18n();
const board = useBoardContext();

const desks = computed(() => board.members.value.filter((m) => m.user !== null));
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

        <p class="border-t border-border pt-3 text-2xs text-muted-foreground">{{ t('board.roster.hint') }}</p>
    </BoardPanel>
</template>
