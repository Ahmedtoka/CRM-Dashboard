<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { addDays, cairoToday } from '@/lib/format';
import type { ReportRange } from '@/types/admin';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ modelValue: ReportRange }>();
const emit = defineEmits<{ 'update:modelValue': [range: ReportRange] }>();

const { t } = useI18n();

type Preset = 'today' | 'yesterday' | 'd7' | 'd30';
const PRESETS: Preset[] = ['today', 'yesterday', 'd7', 'd30'];

// Presets are Cairo calendar days; the server converts them to UTC bounds.
function presetRange(preset: Preset): ReportRange {
    const today = cairoToday();
    switch (preset) {
        case 'yesterday':
            return { from: addDays(today, -1), to: addDays(today, -1) };
        case 'd7':
            return { from: addDays(today, -6), to: today };
        case 'd30':
            return { from: addDays(today, -29), to: today };
        default:
            return { from: today, to: today };
    }
}

const active = computed<Preset | 'custom'>(() => {
    const match = PRESETS.find((p) => {
        const r = presetRange(p);
        return r.from === props.modelValue.from && r.to === props.modelValue.to;
    });
    return match ?? 'custom';
});

const showCustom = ref(active.value === 'custom');
const from = ref(props.modelValue.from);
const to = ref(props.modelValue.to);

watch(
    () => props.modelValue,
    (range) => {
        from.value = range.from;
        to.value = range.to;
    },
);

function choose(preset: Preset): void {
    showCustom.value = false;
    emit('update:modelValue', presetRange(preset));
}

const invalid = computed(() => !from.value || !to.value || to.value < from.value);

function apply(): void {
    if (!invalid.value) emit('update:modelValue', { from: from.value, to: to.value });
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5" role="group" :aria-label="t('range.label')">
        <button
            v-for="preset in PRESETS"
            :key="preset"
            type="button"
            :aria-pressed="active === preset && !showCustom"
            class="h-9 rounded-md border px-2.5 text-xs transition-colors"
            :class="active === preset && !showCustom ? 'border-primary bg-primary text-primary-foreground' : 'bg-background text-muted-foreground hover:text-foreground'"
            @click="choose(preset)"
        >
            {{ t(`range.${preset}`) }}
        </button>
        <button
            type="button"
            :aria-pressed="showCustom || active === 'custom'"
            :aria-expanded="showCustom"
            class="h-9 rounded-md border px-2.5 text-xs transition-colors"
            :class="showCustom || active === 'custom' ? 'border-primary bg-primary text-primary-foreground' : 'bg-background text-muted-foreground hover:text-foreground'"
            @click="showCustom = !showCustom"
        >
            {{ t('range.custom') }}
        </button>
        <form v-if="showCustom" class="flex flex-wrap items-center gap-1.5" @submit.prevent="apply">
            <label class="sr-only" for="range-from">{{ t('range.from') }}</label>
            <input id="range-from" v-model="from" type="date" dir="ltr" class="h-9 rounded-md border border-input bg-background px-2 text-xs" :max="to" />
            <span class="text-xs text-muted-foreground" aria-hidden="true">→</span>
            <label class="sr-only" for="range-to">{{ t('range.to') }}</label>
            <input id="range-to" v-model="to" type="date" dir="ltr" class="h-9 rounded-md border border-input bg-background px-2 text-xs" :min="from" />
            <button type="submit" class="h-9 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground disabled:opacity-50" :disabled="invalid">
                {{ t('range.apply') }}
            </button>
        </form>
    </div>
</template>
