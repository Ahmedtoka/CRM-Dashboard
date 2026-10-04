<script setup lang="ts">
/** The written reasons behind a tier or a stop suggestion (WinnerScorer / StopAdvisor reasons), numbers formatted for the locale. */
import { useI18n } from '@/composables/useI18n';
import { reasonTexts } from '@/lib/ads';
import type { AdReason } from '@/types/ads';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ reasons: AdReason[]; currency?: string }>(), { currency: 'EGP' });

const { locale } = useI18n();

const lines = computed(() => reasonTexts(props.reasons, locale.value, props.currency));
</script>

<template>
    <ul class="space-y-0.5 text-2xs leading-snug">
        <li v-for="l in lines" :key="l.key" class="flex items-start gap-1.5" :class="l.bad ? 'text-destructive' : 'text-muted-foreground'">
            <span class="mt-1 size-1 shrink-0 rounded-full bg-current" aria-hidden="true" />
            <span>{{ l.text }}</span>
        </li>
    </ul>
</template>
