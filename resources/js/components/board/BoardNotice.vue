<script setup lang="ts">
import BoardPanel from '@/components/board/BoardPanel.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useBoardContext } from '@/lib/board/context';
import { Link } from '@inertiajs/vue3';
import { LoaderCircle, RefreshCw, Settings } from 'lucide-vue-next';
import { ref } from 'vue';

// Instead of the day: the queue is switched off (with the way to switch it on), or the state
// could not be read (with a retry).
defineProps<{ kind: 'disabled' | 'failed'; canEditSettings: boolean }>();

const { t } = useI18n();
const board = useBoardContext();

const retrying = ref(false);

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
    <BoardPanel class="w-full max-w-md" :title="kind === 'disabled' ? t('board.disabled.title') : t('board.failed.title')" :closable="false">
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
