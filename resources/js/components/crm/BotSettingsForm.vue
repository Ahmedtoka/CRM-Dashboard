<script setup lang="ts">
import ChipsInput from '@/components/crm/ChipsInput.vue';
import ToggleSwitch from '@/components/crm/ToggleSwitch.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import type { BotSettings } from '@/types/admin';
import { router } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps<{ settings: BotSettings; canEditAi: boolean }>();

const { t, locale } = useI18n();
const api = useApi();
const toast = useToast();
const busy = ref(false);
const error = ref<string | null>(null);

function fromProps(s: BotSettings) {
    return {
        enabled: s.enabled,
        ai_enabled: s.ai_enabled,
        from: s.working_hours?.from ?? '',
        to: s.working_hours?.to ?? '',
        days: [...(s.working_hours?.days ?? [])],
        outside_hours_message: s.outside_hours_message ?? '',
        handover_keywords: [...(s.handover_keywords ?? [])],
        max_bot_turns: s.max_bot_turns,
        min_confidence: Number(s.min_confidence),
        comment_reply_delay_min: s.comment_reply_delay_min,
        comment_reply_delay_max: s.comment_reply_delay_max,
        spam_phrases: [...(s.spam_phrases ?? [])],
        low_value_phrases: [...(s.low_value_phrases ?? [])],
        allowed_link_domains: [...(s.allowed_link_domains ?? [])],
        spam_repeat_threshold: s.spam_repeat_threshold,
        system_prompt: s.system_prompt ?? '',
        burst_wait_seconds: s.burst_wait_seconds,
        burst_max_wait_seconds: s.burst_max_wait_seconds,
        typing_ms_per_char: s.typing_ms_per_char,
        order_lookup_enabled: s.order_lookup_enabled,
    };
}

const form = reactive(fromProps(props.settings));
watch(
    () => props.settings,
    (s) => Object.assign(form, fromProps(s)),
);

// 2023-01-01 was a Sunday (day 0).
const dayNames = computed(() =>
    Array.from({ length: 7 }, (_, d) =>
        new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { weekday: 'short', timeZone: 'UTC' }).format(
            new Date(Date.UTC(2023, 0, 1 + d)),
        ),
    ),
);

function toggleDay(day: number, checked: boolean): void {
    form.days = checked ? [...new Set([...form.days, day])].sort() : form.days.filter((d) => d !== day);
}

async function submit(): Promise<void> {
    const payload: Record<string, unknown> = {
        enabled: form.enabled,
        working_hours: form.from || form.to || form.days.length ? { from: form.from || null, to: form.to || null, days: form.days } : null,
        outside_hours_message: form.outside_hours_message || null,
        handover_keywords: form.handover_keywords,
        max_bot_turns: form.max_bot_turns,
        min_confidence: form.min_confidence,
        comment_reply_delay_min: form.comment_reply_delay_min,
        comment_reply_delay_max: form.comment_reply_delay_max,
        spam_phrases: form.spam_phrases,
        low_value_phrases: form.low_value_phrases,
        allowed_link_domains: form.allowed_link_domains,
        spam_repeat_threshold: form.spam_repeat_threshold,
        burst_wait_seconds: form.burst_wait_seconds,
        burst_max_wait_seconds: form.burst_max_wait_seconds,
        typing_ms_per_char: form.typing_ms_per_char,
        order_lookup_enabled: form.order_lookup_enabled,
    };
    // AI fields are admin-only; sending them as a supervisor is a 403.
    if (props.canEditAi) {
        payload.ai_enabled = form.ai_enabled;
        payload.system_prompt = form.system_prompt || null;
    }
    busy.value = true;
    error.value = null;
    try {
        await api.put('/settings/bot', payload);
        toast.push(t('ui.saved'));
        router.reload({ only: ['settings'] });
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        busy.value = false;
    }
}

const input = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';
const card = 'space-y-4 rounded-lg bg-card p-4 shadow-card';
const row = 'flex items-center justify-between gap-4 rounded-md bg-elevated px-3 py-2.5';
const hint = 'text-2xs text-muted-foreground';
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <!-- Running -->
        <section :class="card" aria-labelledby="bot-run-title">
            <header>
                <h2 id="bot-run-title" class="text-sm font-semibold">{{ t('settings.bot.cards.run') }}</h2>
                <p :class="['mt-0.5', hint]">{{ t('settings.bot.cards.run_hint') }}</p>
            </header>

            <div :class="row">
                <div class="min-w-0">
                    <span class="block text-sm font-semibold">{{ t('settings.bot.enabled') }}</span>
                    <span :class="['block', hint]">{{ t('settings.bot.cards.enabled_hint') }}</span>
                </div>
                <ToggleSwitch v-model="form.enabled" :label="t('settings.bot.enabled')" />
            </div>

            <div :class="row">
                <div class="min-w-0">
                    <span class="block text-sm font-semibold">{{ t('settings.bot.order_lookup') }}</span>
                    <span :class="['block', hint]">{{ t('settings.bot.order_lookup_hint') }}</span>
                </div>
                <ToggleSwitch v-model="form.order_lookup_enabled" :label="t('settings.bot.order_lookup')" />
            </div>

            <label class="grid max-w-xs content-start gap-1">
                <span class="text-sm font-semibold">{{ t('settings.bot.max_turns') }}</span>
                <input v-model.number="form.max_bot_turns" type="number" min="1" max="50" dir="ltr" :class="[input, 'tabular-nums']" />
                <span :class="hint">{{ t('settings.bot.cards.max_turns_hint') }}</span>
            </label>
        </section>

        <!-- Timing -->
        <section :class="card" aria-labelledby="bot-timing-title">
            <header>
                <h2 id="bot-timing-title" class="text-sm font-semibold">{{ t('settings.bot.timing') }}</h2>
                <p :class="['mt-0.5', hint]">{{ t('settings.bot.cards.timing_hint') }}</p>
            </header>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="grid content-start gap-1">
                    <span class="text-sm font-semibold">{{ t('settings.bot.burst_wait') }}</span>
                    <input
                        v-model.number="form.burst_wait_seconds"
                        type="number"
                        min="0"
                        max="60"
                        step="1"
                        dir="ltr"
                        :class="[input, 'tabular-nums']"
                    />
                    <span :class="hint">{{ t('settings.bot.burst_wait_hint') }}</span>
                </label>
                <label class="grid content-start gap-1">
                    <span class="text-sm font-semibold">{{ t('settings.bot.burst_max_wait') }}</span>
                    <input
                        v-model.number="form.burst_max_wait_seconds"
                        type="number"
                        :min="form.burst_wait_seconds || 0"
                        max="120"
                        step="1"
                        dir="ltr"
                        :class="[input, 'tabular-nums']"
                    />
                    <span :class="hint">{{ t('settings.bot.burst_max_wait_hint') }}</span>
                </label>
                <label class="grid content-start gap-1">
                    <span class="text-sm font-semibold">{{ t('settings.bot.typing_ms_per_char') }}</span>
                    <input
                        v-model.number="form.typing_ms_per_char"
                        type="number"
                        min="0"
                        max="120"
                        step="5"
                        dir="ltr"
                        :class="[input, 'tabular-nums']"
                    />
                    <span :class="hint">{{ t('settings.bot.typing_hint') }}</span>
                </label>
                <fieldset class="grid content-start gap-1">
                    <legend class="mb-1 text-sm font-semibold">{{ t('settings.bot.comment_delay') }}</legend>
                    <div class="flex gap-2">
                        <input
                            v-model.number="form.comment_reply_delay_min"
                            type="number"
                            min="0"
                            max="600"
                            dir="ltr"
                            :class="[input, 'tabular-nums']"
                            :aria-label="t('settings.bot.delay_min')"
                        />
                        <input
                            v-model.number="form.comment_reply_delay_max"
                            type="number"
                            :min="form.comment_reply_delay_min"
                            max="600"
                            dir="ltr"
                            :class="[input, 'tabular-nums']"
                            :aria-label="t('settings.bot.delay_max')"
                        />
                    </div>
                    <span :class="hint">{{ t('settings.bot.cards.comment_delay_hint') }}</span>
                </fieldset>
            </div>
        </section>

        <!-- Working hours -->
        <section :class="card" aria-labelledby="bot-hours-title">
            <header>
                <h2 id="bot-hours-title" class="text-sm font-semibold">{{ t('settings.bot.cards.hours') }}</h2>
                <p :class="['mt-0.5', hint]">{{ t('settings.bot.cards.hours_hint') }}</p>
            </header>

            <div class="flex flex-wrap items-center gap-3 text-sm">
                <label class="flex items-center gap-2">
                    {{ t('settings.bot.from') }}
                    <input v-model="form.from" type="time" dir="ltr" class="h-9 rounded-md border border-input bg-background px-2 tabular-nums" />
                </label>
                <label class="flex items-center gap-2">
                    {{ t('settings.bot.to') }}
                    <input v-model="form.to" type="time" dir="ltr" class="h-9 rounded-md border border-input bg-background px-2 tabular-nums" />
                </label>
            </div>
            <div class="flex flex-wrap gap-1.5 text-xs" role="group" :aria-label="t('settings.bot.days')">
                <label
                    v-for="(name, day) in dayNames"
                    :key="day"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-border px-2.5 py-1.5 transition-colors has-[:checked]:border-primary has-[:checked]:bg-surface-accent has-[:checked]:text-primary"
                >
                    <input
                        type="checkbox"
                        class="rounded border-input"
                        :checked="form.days.includes(day)"
                        @change="toggleDay(day, ($event.target as HTMLInputElement).checked)"
                    />{{ name }}
                </label>
            </div>

            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.bot.outside_hours_message') }}</span>
                <textarea
                    v-model="form.outside_hours_message"
                    rows="2"
                    dir="auto"
                    maxlength="1000"
                    class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
                <span :class="hint">{{ t('settings.bot.cards.outside_hours_hint') }}</span>
            </label>
        </section>

        <!-- Words -->
        <section :class="card" aria-labelledby="bot-words-title">
            <header>
                <h2 id="bot-words-title" class="text-sm font-semibold">{{ t('settings.bot.cards.words') }}</h2>
                <p :class="['mt-0.5', hint]">{{ t('settings.bot.cards.words_hint') }}</p>
            </header>

            <div class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.bot.handover_keywords') }}</span>
                <ChipsInput
                    v-model="form.handover_keywords"
                    :label="t('settings.bot.handover_keywords')"
                    :placeholder="t('settings.bot.keyword_placeholder')"
                />
                <span :class="hint">{{ t('settings.bot.cards.handover_hint') }}</span>
            </div>
            <div class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.bot.spam_phrases') }}</span>
                <ChipsInput
                    v-model="form.spam_phrases"
                    :label="t('settings.bot.spam_phrases')"
                    :placeholder="t('settings.bot.keyword_placeholder')"
                />
                <span :class="hint">{{ t('settings.bot.cards.spam_hint') }}</span>
            </div>
            <div class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.bot.low_value_phrases') }}</span>
                <ChipsInput
                    v-model="form.low_value_phrases"
                    :label="t('settings.bot.low_value_phrases')"
                    :placeholder="t('settings.bot.keyword_placeholder')"
                />
                <span :class="hint">{{ t('settings.bot.cards.low_value_hint') }}</span>
            </div>
            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_14rem]">
                <div class="grid content-start gap-1">
                    <span class="text-sm font-semibold">{{ t('settings.bot.allowed_link_domains') }}</span>
                    <ChipsInput v-model="form.allowed_link_domains" :label="t('settings.bot.allowed_link_domains')" placeholder="example.com" />
                    <span :class="hint">{{ t('settings.bot.cards.domains_hint') }}</span>
                </div>
                <label class="grid content-start gap-1">
                    <span class="text-sm font-semibold">{{ t('settings.bot.spam_repeat_threshold') }}</span>
                    <input v-model.number="form.spam_repeat_threshold" type="number" min="1" max="50" dir="ltr" :class="[input, 'tabular-nums']" />
                    <span :class="hint">{{ t('settings.bot.cards.spam_repeat_hint') }}</span>
                </label>
            </div>
        </section>

        <!-- AI -->
        <section :class="card" aria-labelledby="bot-ai-title">
            <header>
                <h2 id="bot-ai-title" class="text-sm font-semibold">{{ t('settings.bot.cards.ai') }}</h2>
                <p :class="['mt-0.5', hint]">{{ t('settings.bot.cards.ai_hint') }}</p>
            </header>

            <div :class="row">
                <div class="min-w-0">
                    <span class="block text-sm font-semibold">{{ t('settings.bot.ai_enabled') }}</span>
                    <span :class="['block', hint]">{{ canEditAi ? t('settings.bot.cards.ai_enabled_hint') : t('settings.bot.ai_admin_only') }}</span>
                </div>
                <ToggleSwitch v-model="form.ai_enabled" :label="t('settings.bot.ai_enabled')" :disabled="!canEditAi" />
            </div>

            <label class="grid max-w-xs content-start gap-1">
                <span class="text-sm font-semibold">{{ t('settings.bot.min_confidence') }}</span>
                <input v-model.number="form.min_confidence" type="number" min="0" max="1" step="0.05" dir="ltr" :class="[input, 'tabular-nums']" />
                <span :class="hint">{{ t('settings.bot.cards.min_confidence_hint') }}</span>
            </label>

            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.bot.system_prompt') }}</span>
                <textarea
                    v-model="form.system_prompt"
                    rows="6"
                    dir="auto"
                    maxlength="10000"
                    class="rounded-md border border-input bg-background px-3 py-2 text-sm disabled:opacity-60"
                    :disabled="!canEditAi"
                />
                <span :class="hint">{{ canEditAi ? t('settings.bot.cards.system_prompt_hint') : t('settings.bot.ai_admin_only') }}</span>
            </label>
        </section>

        <p v-if="error" role="alert" class="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">{{ error }}</p>
        <div class="sticky bottom-0 flex justify-end bg-background/95 py-3 backdrop-blur-sm">
            <button
                type="submit"
                class="inline-flex h-10 items-center gap-1.5 rounded-md bg-primary px-5 text-sm font-medium text-primary-foreground disabled:opacity-50"
                :disabled="busy"
            >
                <LoaderCircle v-if="busy" class="size-4 animate-spin" aria-hidden="true" />{{ t('common.save') }}
            </button>
        </div>
    </form>
</template>
