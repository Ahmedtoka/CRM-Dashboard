<script setup lang="ts">
/**
 * The launch stages as boxes (DESIGN §3 StageBoxes): each box is a stage with its count and choosing one filters the
 * list below. Five across on a desktop, two on a phone.
 */
import { useI18n } from '@/composables/useI18n';
import { formatCount } from '@/lib/format';

export interface StageBox {
    key: string;
    label: string;
    count: number | null;
    /** Waiting work that is the person's to do (mauve number). */
    mine?: boolean;
}

defineProps<{ boxes: StageBox[]; current: string; label: string }>();
const emit = defineEmits<{ select: [key: string] }>();
const { locale } = useI18n();
</script>

<template>
    <div class="grid grid-cols-2 gap-2.5 md:grid-cols-[repeat(auto-fit,minmax(0,1fr))]" role="group" :aria-label="label">
        <button
            v-for="b in boxes"
            :key="b.key"
            type="button"
            :data-box="b.key"
            :aria-pressed="current === b.key"
            class="flex min-h-[72px] flex-col items-start gap-0.5 rounded-lg border bg-card px-3.5 py-3 text-start transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
            :class="current === b.key ? 'border-brand shadow-[inset_0_0_0_1px_hsl(var(--brand))]' : 'border-line hover:border-line-strong'"
            @click="emit('select', b.key)"
        >
            <span class="text-[12.5px] font-semibold text-fg-2">{{ b.label }}</span>
            <span class="text-2xl font-bold leading-tight tabular-nums" :class="b.mine && (b.count ?? 0) > 0 ? 'text-brand-ink' : 'text-foreground'">{{
                b.count === null ? '—' : formatCount(b.count, locale)
            }}</span>
        </button>
    </div>
</template>
