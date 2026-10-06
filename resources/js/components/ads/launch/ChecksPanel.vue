<script setup lang="ts">
/** The launch checks (spec 3.4): blocking first, then warnings (tick «شُفت» when approving), passed ones folded. */
import { useI18n } from '@/composables/useI18n';
import { checkMessage } from '@/lib/launch';
import type { CheckRow } from '@/types/ads';
import { CircleAlert, CircleCheck, CircleX, LoaderCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = withDefaults(defineProps<{ checks: CheckRow[] | null; loading?: boolean; ackable?: boolean; acked?: string[] }>(), {
    loading: false,
    ackable: false,
    acked: () => [],
});
const emit = defineEmits<{ 'update:acked': [keys: string[]] }>();

const { t, locale } = useI18n();
const showPassed = ref(false);
const blocking = computed(() => (props.checks ?? []).filter((c) => c.level === 'block'));
const warnings = computed(() => (props.checks ?? []).filter((c) => c.level === 'warn'));
const passed = computed(() => (props.checks ?? []).filter((c) => c.level === 'pass'));

function toggle(key: string, on: boolean): void {
    const next = new Set(props.acked);
    if (on) next.add(key);
    else next.delete(key);
    emit('update:acked', [...next]);
}
</script>

<template>
    <section :aria-label="t('ads.launch.checks.title')" class="space-y-2 text-sm">
        <p v-if="loading" role="status" class="flex items-center gap-2 text-xs text-muted-foreground">
            <LoaderCircle class="size-4 animate-spin" aria-hidden="true" />{{ t('ads.launch.editor.checks_loading') }}
        </p>
        <p v-else-if="!checks || !checks.length" class="text-xs text-muted-foreground">{{ t('ads.launch.checks.none') }}</p>
        <template v-else>
            <div v-if="blocking.length" class="rounded-md border border-destructive/40 bg-destructive/5 p-2">
                <p class="mb-1 text-xs font-semibold text-destructive">{{ t('ads.launch.checks.blocking', { n: blocking.length }) }}</p>
                <ul class="space-y-1">
                    <li v-for="c in blocking" :key="c.key" class="flex items-start gap-2">
                        <CircleX class="mt-0.5 size-4 shrink-0 text-destructive" aria-hidden="true" />
                        <span>{{ checkMessage(c, locale) }}</span>
                    </li>
                </ul>
            </div>
            <div v-if="warnings.length" class="rounded-md border border-warning/50 bg-warning/10 p-2">
                <p class="mb-1 text-xs font-semibold text-amber-900 dark:text-amber-100">
                    {{ t('ads.launch.checks.warnings', { n: warnings.length }) }}
                </p>
                <ul class="space-y-1">
                    <li v-for="c in warnings" :key="c.key" class="flex items-start gap-2">
                        <CircleAlert class="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-300" aria-hidden="true" />
                        <span class="flex-1">{{ checkMessage(c, locale) }}</span>
                        <label v-if="ackable" class="flex shrink-0 cursor-pointer items-center gap-1 text-xs font-medium">
                            <input
                                type="checkbox"
                                class="size-4 accent-primary"
                                :checked="acked.includes(c.key)"
                                @change="toggle(c.key, ($event.target as HTMLInputElement).checked)"
                            />{{ t('ads.launch.checks.seen') }}
                        </label>
                    </li>
                </ul>
            </div>
            <p v-if="!blocking.length && !warnings.length" class="flex items-center gap-2 text-xs font-medium text-emerald-700 dark:text-emerald-300">
                <CircleCheck class="size-4" aria-hidden="true" />{{ t('ads.launch.checks.all_passed') }}
            </p>
            <button
                v-if="passed.length"
                type="button"
                class="text-xs text-muted-foreground underline-offset-2 hover:underline"
                :aria-expanded="showPassed"
                @click="showPassed = !showPassed"
            >
                {{ t('ads.launch.checks.passed', { n: passed.length }) }}
            </button>
            <ul v-if="showPassed" class="space-y-1">
                <li v-for="c in passed" :key="c.key" class="flex items-start gap-2 text-muted-foreground">
                    <CircleCheck class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                    <span>{{ checkMessage(c, locale) }}</span>
                </li>
            </ul>
        </template>
    </section>
</template>
