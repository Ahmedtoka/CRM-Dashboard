<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatCount } from '@/lib/format';
import { OUTCOME_GROUPS, outcomeDigit, outcomeForKey } from '@/lib/outcomes';
import { cn } from '@/lib/utils';
import type { AgentOutcome, OutcomeKey } from '@/types/crm';
import { LoaderCircle } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';

/**
 * «إيه اللي حصل في الشات؟» (finish-api.md §1): ONE pick out of three labelled rows — a sale, a
 * service chat, the rest. A tap or a digit (1-9) picks; «حاجة تانية» asks for a note. Nothing is
 * locked: the sheet preselects the suggestion and she confirms or changes it. Plain buttons with
 * radio semantics, so it works inside a reka dropdown without stealing its items.
 */
const props = withDefaults(
    defineProps<{
        /** The automatic outcome the server would record on its own (shown as «تلقائي» beside it when picked). */
        auto?: OutcomeKey | null;
        /** The chat's outcome state is still loading: a disabled row, no pick. */
        loading?: boolean;
        /** `sheet`: the «خلصت» sheet's roomier chips, its own label above (no title row here). */
        variant?: 'menu' | 'sheet';
        /** The radiogroup's accessible name when the title row is hidden. */
        label?: string;
    }>(),
    { auto: null, loading: false, variant: 'menu', label: '' },
);
const picked = defineModel<AgentOutcome | null>({ required: true });
const note = defineModel<string>('note', { default: '' });

const { t, locale } = useI18n();
const root = ref<HTMLElement | null>(null);

function choose(value: AgentOutcome): void {
    picked.value = value;
    if (value === 'other') void nextTick(() => root.value?.querySelector<HTMLInputElement>('input[data-outcome-note]')?.focus());
}

defineExpose({
    /** A digit key inside the menu: true when it picked something (the caller stops the key there). */
    handleKey: (key: string): boolean => {
        if (props.loading) return false;
        const value = outcomeForKey(key);
        if (value === null) return false;
        choose(value);

        return true;
    },
    focus: (): void =>
        (
            root.value?.querySelector<HTMLElement>('[data-outcome][aria-checked="true"]') ?? root.value?.querySelector<HTMLElement>('[data-outcome]')
        )?.focus(),
});

const sheet = computed(() => props.variant === 'sheet');
const chip = computed(() =>
    cn(
        'inline-flex items-center gap-1.5 border transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        sheet.value ? 'min-h-[36px] rounded-md px-3 text-13 font-semibold' : 'h-7 rounded-full px-2.5 text-xs',
    ),
);
const on = computed(() =>
    sheet.value ? 'border-brand bg-brand-soft text-brand-ink' : 'border-primary bg-surface-accent font-semibold text-primary',
);
const off = computed(() => (sheet.value ? 'border-line-strong bg-card text-fg-2 hover:bg-sunken' : 'border-border hover:bg-elevated'));
const groupLabel = computed(() => props.label || t('outcomes.title'));
</script>

<template>
    <div ref="root" :class="sheet ? 'space-y-3' : 'space-y-2 px-2 py-1.5'" data-outcome-picker>
        <p v-if="!sheet" class="flex items-center justify-between text-2xs font-medium text-muted-foreground">
            <span>{{ t('outcomes.title') }}</span>
            <span v-if="!loading">{{ t('outcomes.digits_hint') }}</span>
        </p>

        <p
            v-if="loading"
            class="flex items-center gap-1.5 rounded-md bg-muted px-2 py-1.5 text-xs text-muted-foreground"
            role="status"
            data-outcome-loading
        >
            <LoaderCircle class="size-3.5 shrink-0 animate-spin" aria-hidden="true" />
            {{ t('ui.loading') }}
        </p>

        <template v-else>
            <div v-for="group in OUTCOME_GROUPS" :key="group.key" class="space-y-1" :data-outcome-group="group.key">
                <p :class="cn('font-medium text-fg-3', sheet ? 'text-xs' : 'text-2xs')" :id="`outcome-group-${group.key}`">
                    {{ t(`outcomes.groups.${group.key}`) }}
                </p>
                <div
                    class="flex flex-wrap"
                    :class="sheet ? 'gap-1.5' : 'gap-1'"
                    role="radiogroup"
                    :aria-labelledby="`outcome-group-${group.key}`"
                    :aria-label="groupLabel"
                >
                    <button
                        v-for="value in group.outcomes"
                        :key="value"
                        type="button"
                        role="radio"
                        :aria-checked="picked === value"
                        :data-outcome="value"
                        :class="[chip, picked === value ? on : off]"
                        @click="choose(value)"
                    >
                        <span
                            v-if="outcomeDigit(value) !== null"
                            class="tabular-nums"
                            :class="picked === value && sheet ? 'text-brand-ink/70' : 'text-muted-foreground'"
                            aria-hidden="true"
                            >{{ formatCount(outcomeDigit(value), locale) }}</span
                        >{{ t(`outcomes.${value}`)
                        }}<span v-if="auto === value && picked === value" class="text-2xs font-normal" data-outcome-auto
                            >· {{ t('outcomes.auto') }}</span
                        >
                    </button>
                </div>
            </div>
            <input
                v-if="picked === 'other'"
                v-model="note"
                data-outcome-note
                maxlength="120"
                dir="auto"
                :class="
                    sheet
                        ? 'h-10 w-full rounded-md border border-line-strong bg-card px-3 text-sm focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand-soft'
                        : 'h-8 w-full rounded-md border border-input bg-background px-2 text-xs'
                "
                :placeholder="t('outcomes.note_placeholder')"
                :aria-label="t('outcomes.note_placeholder')"
                @keydown.stop
            />
        </template>
    </div>
</template>
