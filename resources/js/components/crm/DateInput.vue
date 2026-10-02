<script setup lang="ts">
/**
 * A native date field that reads right in Arabic. Chrome draws an empty field's segment placeholder
 * («يوم/شهر/سنة») letter by letter left to right, so it shows reversed whatever the `dir`. On
 * Chromium only (feature-detected `::-webkit-datetime-edit`), while the field is empty, not focused
 * and not half typed, the native text is hidden and our own placeholder is drawn over it: three
 * isolated runs in the field's own left-to-right segment order (dd / mm / yyyy). Other browsers keep
 * their native placeholder.
 */
import { useI18n } from '@/composables/useI18n';
import { computed, ref } from 'vue';

defineOptions({ inheritAttrs: false });

withDefaults(defineProps<{ min?: string; max?: string; wrapperClass?: string }>(), { min: undefined, max: undefined, wrapperClass: undefined });

const model = defineModel<string>({ default: '' });
const { t } = useI18n();

const chromium = typeof CSS !== 'undefined' && typeof CSS.supports === 'function' && CSS.supports('selector(::-webkit-datetime-edit)');
const focused = ref(false);
/** Some segments typed but not a full date yet: the value is still '' — keep what she typed visible. */
const partial = ref(false);
const overlay = computed(() => chromium && !model.value && !focused.value && !partial.value);

function sync(event: Event): void {
    const input = event.target as HTMLInputElement;
    model.value = input.value;
    partial.value = input.value === '' && input.validity.badInput;
}

function blur(event: Event): void {
    focused.value = false;
    partial.value = (event.target as HTMLInputElement).validity.badInput;
}
</script>

<template>
    <span class="relative inline-flex" :class="wrapperClass" dir="ltr">
        <input
            v-bind="$attrs"
            :value="model"
            type="date"
            dir="ltr"
            :min="min"
            :max="max"
            class="min-w-[9.5rem]"
            :class="overlay ? '[&::-webkit-datetime-edit]:text-transparent' : ''"
            @input="sync"
            @focus="focused = true"
            @blur="blur"
        />
        <span v-if="overlay" class="pointer-events-none absolute inset-y-0 end-0 flex items-center pe-2 text-xs text-muted-foreground" aria-hidden="true">
            <bdi>{{ t('range.date_day') }}</bdi>/<bdi>{{ t('range.date_month') }}</bdi>/<bdi>{{ t('range.date_year') }}</bdi>
        </span>
    </span>
</template>
