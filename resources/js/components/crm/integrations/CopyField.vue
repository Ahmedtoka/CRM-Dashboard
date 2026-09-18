<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { Copy } from 'lucide-vue-next';

const props = defineProps<{ label: string; value: string }>();

const { t } = useI18n();
const toast = useToast();

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.value);
        toast.push(t('ui.copied'), 'info');
    } catch {
        toast.push(t('common.error'), 'error');
    }
}
</script>

<template>
    <div class="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-1">
        <span class="text-2xs font-semibold text-muted-foreground">{{ label }}</span>
        <div class="flex items-center gap-1">
            <code class="min-w-0 flex-1 truncate rounded bg-muted px-2 py-1.5 text-2xs" dir="ltr" :title="value">{{ value || '—' }}</code>
            <button
                v-if="value"
                type="button"
                class="grid size-8 shrink-0 place-items-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                :aria-label="`${t('ui.copy')} ${label}`"
                @click="copy"
            >
                <Copy class="size-3.5" aria-hidden="true" />
            </button>
        </div>
    </div>
</template>
