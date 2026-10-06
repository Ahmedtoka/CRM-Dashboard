<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatCount } from '@/lib/format';
import { MORE_OUTCOMES, PRIMARY_OUTCOMES, outcomeForKey } from '@/lib/outcomes';
import type { AgentOutcome, OutcomeKey } from '@/types/crm';
import { LoaderCircle, Lock } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';

/**
 * The outcome row of «خلصت» (C 3.1, D13): one tap or one digit (1-4) for the four sales reasons,
 * a second row for the rest; locked when an order exists in this chat (automatic `ordered`).
 * Plain buttons (radio semantics), so it works inside a reka dropdown without stealing its items.
 */
const props = withDefaults(
    defineProps<{
        auto?: OutcomeKey | null;
        /** The chat's outcome state is still loading: a disabled row, no pick (an ordered chat may be about to lock). */
        loading?: boolean;
    }>(),
    { auto: null, loading: false },
);
const picked = defineModel<AgentOutcome | null>({ required: true });
const note = defineModel<string>('note', { default: '' });

const { t, locale } = useI18n();
const root = ref<HTMLElement | null>(null);
const locked = computed(() => props.auto === 'ordered');
/** An untouched automatic service shows as chosen; a pick replaces it. */
const shown = computed<AgentOutcome | null>(() => picked.value ?? (props.auto === 'service' ? 'service' : null));

function choose(value: AgentOutcome): void {
    picked.value = value;
    if (value === 'other') void nextTick(() => root.value?.querySelector<HTMLInputElement>('input[data-outcome-note]')?.focus());
}

defineExpose({
    /** A digit key inside the menu: true when it picked something (the caller stops the key there). */
    handleKey: (key: string): boolean => {
        if (locked.value || props.loading) return false;
        const value = outcomeForKey(key);
        if (value === null) return false;
        choose(value);

        return true;
    },
    focus: (): void => root.value?.querySelector<HTMLElement>('[data-outcome]')?.focus(),
});

const chip =
    'inline-flex h-7 items-center gap-1 rounded-full border px-2.5 text-xs transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
</script>

<template>
    <div ref="root" class="space-y-1.5 px-2 py-1.5" data-outcome-picker>
        <p class="flex items-center justify-between text-2xs font-medium text-muted-foreground">
            <span>{{ t('outcomes.title') }}</span>
            <span v-if="!locked && !loading">{{ t('outcomes.digits_hint') }}</span>
        </p>

        <p v-if="loading" class="flex items-center gap-1.5 rounded-md bg-muted px-2 py-1.5 text-xs text-muted-foreground" role="status" data-outcome-loading>
            <LoaderCircle class="size-3.5 shrink-0 animate-spin" aria-hidden="true" />
            {{ t('ui.loading') }}
        </p>

        <p
            v-else-if="locked"
            class="flex items-center gap-1.5 rounded-md bg-success/10 px-2 py-1.5 text-xs text-foreground"
            :title="t('outcomes.locked_hint')"
            data-outcome-locked
        >
            <Lock class="size-3.5 shrink-0" aria-hidden="true" />
            {{ t('outcomes.ordered') }} · {{ t('outcomes.auto') }}
        </p>

        <template v-else>
            <div class="flex flex-wrap gap-1" role="radiogroup" :aria-label="t('outcomes.title')">
                <button
                    v-for="(value, index) in PRIMARY_OUTCOMES"
                    :key="value"
                    type="button"
                    role="radio"
                    :aria-checked="shown === value"
                    :data-outcome="value"
                    :class="[chip, shown === value ? 'border-primary bg-surface-accent font-semibold text-primary' : 'border-border hover:bg-elevated']"
                    @click="choose(value)"
                >
                    <span class="tabular-nums text-muted-foreground">{{ formatCount(index + 1, locale) }}</span>{{ t(`outcomes.${value}`) }}
                </button>
            </div>
            <div class="flex flex-wrap gap-1" role="radiogroup" :aria-label="t('outcomes.more')">
                <button
                    v-for="value in MORE_OUTCOMES"
                    :key="value"
                    type="button"
                    role="radio"
                    :aria-checked="shown === value"
                    :data-outcome="value"
                    :class="[
                        chip,
                        shown === value ? 'border-primary bg-surface-accent font-semibold text-primary' : 'border-border text-muted-foreground hover:bg-elevated',
                    ]"
                    @click="choose(value)"
                >
                    {{ t(`outcomes.${value}`) }}<span v-if="value === 'service' && auto === 'service' && picked === null" class="text-2xs"
                        >· {{ t('outcomes.auto') }}</span
                    >
                </button>
            </div>
            <input
                v-if="picked === 'other'"
                v-model="note"
                data-outcome-note
                maxlength="120"
                dir="auto"
                class="h-8 w-full rounded-md border border-input bg-background px-2 text-xs"
                :placeholder="t('outcomes.note_placeholder')"
                :aria-label="t('outcomes.note_placeholder')"
                @keydown.stop
            />
        </template>
    </div>
</template>
