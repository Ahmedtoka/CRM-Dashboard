<script setup lang="ts">
import BoardKpiBar from '@/components/board/BoardKpiBar.vue';
import BoardNotice from '@/components/board/BoardNotice.vue';
import BoardPhone from '@/components/board/BoardPhone.vue';
import BoardRoom from '@/components/board/BoardRoom.vue';
import BoardSidePanel from '@/components/board/BoardSidePanel.vue';
import SkeletonList from '@/components/crm/SkeletonList.vue';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { useBoard } from '@/composables/useBoard';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { provideBoard } from '@/lib/board/context';
import type { BoardSelection } from '@/types/board';
import { Head } from '@inertiajs/vue3';
import { useEventListener, useMediaQuery } from '@vueuse/core';
import { LoaderCircle } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

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
/** A phone: the scaled room is unreadable there, so desks and the lounge come as cards (spec §2.2). */
const narrow = useMediaQuery('(max-width: 767px)');

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
    const same =
        next !== null &&
        selection.value !== null &&
        next.kind === selection.value.kind &&
        'id' in next &&
        'id' in selection.value &&
        next.id === selection.value.id;
    selection.value = same ? null : next;
    board.clearError();
}

function typing(target: EventTarget | null): boolean {
    const el = target as HTMLElement | null;

    return el !== null && ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName);
}

useEventListener(document, 'keydown', (event: KeyboardEvent) => {
    if (event.key !== 'Escape' || selection.value === null) return;
    if (typing(event.target)) return;
    selection.value = null;
});

/** The phone's bottom sheet: Escape while typing keeps it open, as in the room. */
function onSheetEscape(event: KeyboardEvent): void {
    if (typing(event.target) || typing(document.activeElement)) event.preventDefault();
}

// The phone's «عرض الصالة»: the room fullscreen, landscape when the phone allows it.
const phoneRoom = ref(false);
const phoneRoomEl = ref<HTMLElement | null>(null);

async function openPhoneRoom(): Promise<void> {
    phoneRoom.value = true;
    await nextTick();
    const el = phoneRoomEl.value;
    try {
        if (el && typeof el.requestFullscreen === 'function') await el.requestFullscreen();
    } catch {
        // Refused: the room still fills the window (a fixed layer).
    }
    try {
        const orientation = screen.orientation as ScreenOrientation & { lock?: (o: string) => Promise<void> };
        await orientation.lock?.('landscape');
    } catch {
        // Not supported (most desktop browsers, iOS): it just shows full screen.
    }
}

async function closePhoneRoom(): Promise<void> {
    phoneRoom.value = false;
    try {
        screen.orientation?.unlock?.();
    } catch {
        // Nothing was locked, or the browser cannot.
    }
    if (document.fullscreenElement !== null) await document.exitFullscreen().catch(() => undefined);
}

useEventListener(document, 'fullscreenchange', () => {
    if (phoneRoom.value && document.fullscreenElement === null) phoneRoom.value = false;
});
/** The phone's view stays while its room is shown, even after the phone turned to landscape (wider than 767 px). */
const phoneMode = computed(() => narrow.value || phoneRoom.value);
</script>

<template>
    <Head :title="t('board.title')" />

    <AppLayout :breadcrumbs="breadcrumbs" workspace>
        <div class="w-full space-y-3 p-3 md:p-4">
            <!-- Phone: the numbers, then the desks and the lounge as cards. -->
            <template v-if="phoneMode">
                <!-- Behind the open room nothing of the phone view is drawn, so its clocks stop. -->
                <SkeletonList v-if="!phoneRoom && veil === 'loading'" variant="tiles" :count="4" />
                <BoardKpiBar
                    v-else-if="!phoneRoom"
                    phone
                    :roster-open="selection?.kind === 'roster'"
                    :can-roster="canManage && board.shift.value !== null"
                    @full="openPhoneRoom"
                    @roster="select(selection?.kind === 'roster' ? null : { kind: 'roster' })"
                />
                <template v-if="!phoneRoom">
                    <p
                        v-if="veil === 'loading'"
                        class="flex items-center gap-2 rounded-lg bg-card px-4 py-3 text-sm text-card-foreground shadow"
                        role="status"
                    >
                        <LoaderCircle class="size-4 animate-spin" aria-hidden="true" />
                        {{ t('board.loading') }}
                    </p>
                    <BoardNotice v-else-if="veil !== null" class="max-w-none" :kind="veil" :can-edit-settings="canEditSettings" />
                    <BoardPhone v-else :selection="selection" @select="select" />
                </template>

                <Sheet v-if="!phoneRoom" :open="selection !== null && veil === null" @update:open="(open: boolean) => !open && (selection = null)">
                    <SheetContent
                        side="bottom"
                        class="max-h-[85svh] overflow-y-auto rounded-t-2xl p-0 [&>button:last-child]:hidden"
                        @escape-key-down="onSheetEscape"
                    >
                        <SheetTitle class="sr-only">{{ t('board.title') }}</SheetTitle>
                        <BoardSidePanel :selection="selection" :can-manage="canManage" @select="select" />
                    </SheetContent>
                </Sheet>

                <div v-if="phoneRoom" ref="phoneRoomEl" class="fixed inset-0 z-[60] bg-background">
                    <BoardRoom
                        fill
                        :selection="selection"
                        :veiled="veil !== null"
                        :sided="selection !== null"
                        :can-manage="canManage"
                        @select="select"
                        @exit="closePhoneRoom"
                    >
                        <template #side>
                            <BoardSidePanel :selection="selection" :can-manage="canManage" @select="select" />
                        </template>
                    </BoardRoom>
                </div>
            </template>

            <template v-else>
                <BoardRoom
                    :selection="selection"
                    :veiled="veil !== null"
                    :loading="veil === 'loading'"
                    :sided="selection !== null && wide"
                    :can-manage="canManage"
                    @select="select"
                >
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
                            <BoardNotice
                                v-if="veil === 'disabled' || veil === 'failed' || veil === 'closed'"
                                :kind="veil"
                                :can-edit-settings="canEditSettings"
                            />
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
            </template>
        </div>
    </AppLayout>
</template>
