<script setup lang="ts">
/**
 * A native date field that reads right in Arabic. Chrome draws an empty field's segment placeholder
 * («يوم/شهر/سنة») letter by letter left to right, so it shows reversed («موي/رهش/قنس») whatever the
 * `dir`. While the field is empty and not focused, the native text is hidden and our own
 * placeholder is drawn over it; the field itself stays left-to-right (dd/mm/yyyy digits).
 */
import { useI18n } from '@/composables/useI18n';
import { ref } from 'vue';

defineOptions({ inheritAttrs: false });

withDefaults(defineProps<{ min?: string; max?: string; wrapperClass?: string }>(), { min: undefined, max: undefined, wrapperClass: undefined });

const model = defineModel<string>({ default: '' });
const focused = ref(false);
const { t } = useI18n();
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
            :class="!model && !focused ? '[&::-webkit-datetime-edit]:text-transparent' : ''"
            @input="model = ($event.target as HTMLInputElement).value"
            @focus="focused = true"
            @blur="focused = false"
        />
        <span v-if="!model && !focused" class="pointer-events-none absolute inset-y-0 end-0 flex items-center pe-2 text-xs text-muted-foreground" aria-hidden="true">
            {{ t('range.date_placeholder') }}
        </span>
    </span>
</template>
