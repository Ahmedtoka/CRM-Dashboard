<script setup lang="ts">
import BoardNotice from '@/components/board/BoardNotice.vue';
import BoardRoom from '@/components/board/BoardRoom.vue';
import BoardSidePanel from '@/components/board/BoardSidePanel.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import { useBoard } from '@/composables/useBoard';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { provideBoard } from '@/lib/board/context';
import type { BoardSelection } from '@/types/board';
import { Head } from '@inertiajs/vue3';
import { useEventListener, useMediaQuery } from '@vueuse/core';
import { LoaderCircle } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/**
 * «اللوحة الحية»: the room of the approved simulator drawn from the real queue. Only people who
 * may run the board reach this page (BoardAccess: supervisors, admins, the open shift's leader),
 * so everybody here may act on it.
 */
const props = defineProps<{ enabled: boolean; canEditSettings: boolean }>();

const { t } = useI18n();
const board = useBoard({ enabled: props.enabled });
provideBoard(board);

const breadcrumbs = computed(() => [{ title: t('board.title'), href: '/board' }]);
const canManage = true;

/** Wide screens show the panels over the room; narrow ones under it, where they have room. */
const wide = useMediaQuery('(min-width: 1024px)');

const selection = ref<BoardSelection>(null);

/** What lies over the room instead of the day: a notice (no shift runs: they open by the clock). */
const veil = computed<'loading' | 'failed' | 'disabled' | 'closed' | null>(() => {
    if (!board.enabled.value) return 'disabled';
    if (board.failed.value) return 'failed';
    if (!board.loaded.value) return 'loading';

    return board.shift.value === null ? 'closed' : null;
});

// A desk or a customer picked before the room was veiled means nothing any more.
watch(veil, (v) => {
    if (v !== null) selection.value = null;
});

function select(next: BoardSelection): void {
    // Clicking the desk (or customer) that is already open closes its panel.
    const same = next !== null && selection.value !== null && next.kind === selection.value.kind && 'id' in next && 'id' in selection.value && next.id === selection.value.id;
    selection.value = same ? null : next;
    board.clearError();
}

useEventListener(document, 'keydown', (event: KeyboardEvent) => {
    if (event.key !== 'Escape' || selection.value === null) return;
    const target = event.target as HTMLElement | null;
    if (target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) return;
    selection.value = null;
});
</script>

<template>
    <Head :title="t('board.title')" />

    <AppLayout :breadcrumbs="breadcrumbs" workspace>
        <div class="w-full space-y-3 p-3 md:p-4">
            <PageHeader :title="t('board.title')" :description="t('board.description')" />

            <BoardRoom :selection="selection" :veiled="veil !== null" :sided="selection !== null && wide" :can-manage="canManage" @select="select">
                <template #veil>
                    <p
                        v-if="veil === 'loading'"
                        class="flex items-center gap-2 rounded-lg bg-card px-4 py-3 text-sm text-card-foreground shadow"
                        role="status"
                    >
                        <LoaderCircle class="size-4 animate-spin" aria-hidden="true" />
                        {{ t('board.loading') }}
                    </p>
                    <template v-else-if="wide">
                        <BoardNotice v-if="veil === 'disabled' || veil === 'failed' || veil === 'closed'" :kind="veil" :can-edit-settings="canEditSettings" />
                    </template>
                </template>

                <template #side>
                    <BoardSidePanel :selection="selection" :can-manage="canManage" @select="select" />
                </template>
            </BoardRoom>

            <!-- Narrow screens: what the room would show over itself comes under it. -->
            <template v-if="!wide">
                <BoardNotice
                    v-if="veil === 'disabled' || veil === 'failed' || veil === 'closed'"
                    class="max-w-none"
                    :kind="veil"
                    :can-edit-settings="canEditSettings"
                />
                <BoardSidePanel v-else-if="veil === null" :selection="selection" :can-manage="canManage" @select="select" />
            </template>
        </div>
    </AppLayout>
</template>
