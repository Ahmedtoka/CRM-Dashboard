<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatUntil } from '@/lib/format';
import type { WindowMode } from '@/types/crm';
import { Ban, Clock, FileText, ShieldAlert } from 'lucide-vue-next';
import { computed, watch } from 'vue';

const props = defineProps<{ mode: WindowMode; expiresAt: string | null; now: number }>();
const emit = defineEmits<{ expired: [] }>();

const { t, locale } = useI18n();

const msLeft = computed(() => (props.expiresAt ? Date.parse(props.expiresAt) - props.now : null));
const remaining = computed(() => formatUntil(props.expiresAt, locale.value, props.now) ?? t('time.now'));

// Ask the parent to refresh the window state when the countdown crosses zero (spec §5.6).
watch(msLeft, (left, previous) => {
    if (left !== null && left <= 0 && (previous === null || previous === undefined || previous > 0)) emit('expired');
});

const TWO_HOURS = 2 * 60 * 60 * 1000;

const view = computed(() => {
    switch (props.mode) {
        case 'open':
            return {
                icon: Clock,
                text: t('window.open', { time: remaining.value }),
                tone: msLeft.value !== null && msLeft.value < TWO_HOURS ? 'amber' : 'muted',
            };
        case 'human_agent':
            return { icon: ShieldAlert, text: t('window.human_agent', { time: remaining.value }), tone: 'amber' };
        case 'template_only':
            return { icon: FileText, text: t('window.template_only'), tone: 'amber' };
        default:
            return { icon: Ban, text: t('window.closed'), tone: 'red' };
    }
});

const tones: Record<string, string> = {
    muted: 'bg-muted/50 text-muted-foreground',
    amber: 'border-amber-200 bg-amber-50 text-amber-900',
    red: 'border-red-200 bg-red-50 text-red-700',
};
</script>

<template>
    <div role="status" class="flex items-center gap-2 border-t px-4 py-1.5 text-xs" :class="tones[view.tone]">
        <component :is="view.icon" class="size-3.5 shrink-0" aria-hidden="true" />
        <span class="tabular-nums">{{ view.text }}</span>
    </div>
</template>
