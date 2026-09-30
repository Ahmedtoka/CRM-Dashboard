<script setup lang="ts">
import BoardPanel from '@/components/board/BoardPanel.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useBoardContext } from '@/lib/board/context';
import { formatClock } from '@/lib/format';
import { Link } from '@inertiajs/vue3';
import { LoaderCircle, RefreshCw, Settings } from 'lucide-vue-next';
import { computed, ref } from 'vue';

// Instead of the day: the queue is switched off (with the way to switch it on), no shift runs
// now (when the next one starts: shifts open and close by the clock), or the state could not
// be read (with a retry).
defineProps<{ kind: 'disabled' | 'failed' | 'closed'; canEditSettings: boolean }>();

const { t, locale } = useI18n();
const board = useBoardContext();

const retrying = ref(false);

/** No shift runs: it is opening now, or when the next one starts. */
const next = computed(() => {
    if (board.shiftOpening.value) return t('board.closed.opening');
    const at = board.nextShiftStartsAt.value;

    return at ? t('board.closed.next', { time: formatClock(at, locale.value) }) : t('board.closed.none');
});

async function retry(): Promise<void> {
    retrying.value = true;
    try {
        await board.refresh();
    } catch {
        // Still unreachable: the notice stays.
    } finally {
        retrying.value = false;
    }
}
</script>

<template>
    <BoardPanel class="w-full max-w-md" :title="t(`board.${kind}.title`)" :closable="false">
        <template v-if="kind === 'disabled'">
            <p class="text-foreground">{{ t('board.disabled.body') }}</p>
            <Button v-if="canEditSettings" as-child>
                <Link href="/settings/queue">
                    <Settings aria-hidden="true" />
                    {{ t('board.disabled.settings') }}
                </Link>
            </Button>
            <p v-else class="text-xs text-muted-foreground">{{ t('board.disabled.ask') }}</p>
        </template>
        <template v-else-if="kind === 'closed'">
            <p class="text-foreground">{{ next }}</p>
            <p class="text-xs text-muted-foreground">{{ t('board.closed.body') }}</p>
            <Button v-if="canEditSettings" variant="outline" as-child>
                <Link href="/settings/queue">
                    <Settings aria-hidden="true" />
                    {{ t('board.closed.settings') }}
                </Link>
            </Button>
        </template>
        <template v-else>
            <p class="text-foreground">{{ t('board.failed.body') }}</p>
            <Button variant="outline" :disabled="retrying" @click="retry">
                <LoaderCircle v-if="retrying" class="animate-spin" aria-hidden="true" />
                <RefreshCw v-else aria-hidden="true" />
                {{ t('board.failed.retry') }}
            </Button>
        </template>
    </BoardPanel>
</template>
