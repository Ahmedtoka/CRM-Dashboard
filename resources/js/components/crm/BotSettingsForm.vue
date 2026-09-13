<script setup lang="ts">
import ChipsInput from '@/components/crm/ChipsInput.vue';
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
    };
}

const form = reactive(fromProps(props.settings));
watch(
    () => props.settings,
    (s) => Object.assign(form, fromProps(s)),
);

// 2023-01-01 was a Sunday (day 0).
const dayNames = computed(() =>
    Array.from({ length: 7 }, (_, d) => new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { weekday: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(2023, 0, 1 + d)))),
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
</script>

<template>
    <form class="space-y-4 rounded-lg border bg-card p-4" @submit.prevent="submit">
        <h2 class="text-sm font-medium">{{ t('settings.bot.general') }}</h2>

        <div class="flex flex-wrap gap-4 text-sm">
            <label class="flex items-center gap-2"><input v-model="form.enabled" type="checkbox" class="rounded border-input" />{{ t('settings.bot.enabled') }}</label>
            <label class="flex items-center gap-2" :title="canEditAi ? undefined : t('settings.bot.ai_admin_only')">
                <input v-model="form.ai_enabled" type="checkbox" class="rounded border-input" :disabled="!canEditAi" />{{ t('settings.bot.ai_enabled') }}
            </label>
        </div>

        <fieldset class="grid gap-2">
            <legend class="mb-1 text-xs font-medium">{{ t('settings.bot.working_hours') }}</legend>
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <label class="flex items-center gap-1">{{ t('settings.bot.from') }} <input v-model="form.from" type="time" dir="ltr" class="h-8 rounded-md border border-input bg-background px-2" /></label>
                <label class="flex items-center gap-1">{{ t('settings.bot.to') }} <input v-model="form.to" type="time" dir="ltr" class="h-8 rounded-md border border-input bg-background px-2" /></label>
            </div>
            <div class="flex flex-wrap gap-1.5 text-xs" role="group" :aria-label="t('settings.bot.days')">
                <label v-for="(name, day) in dayNames" :key="day" class="inline-flex items-center gap-1 rounded-md border px-2 py-1">
                    <input type="checkbox" class="rounded border-input" :checked="form.days.includes(day)" @change="toggleDay(day, ($event.target as HTMLInputElement).checked)" />{{ name }}
                </label>
            </div>
        </fieldset>

        <label class="grid gap-1">
            <span class="text-xs font-medium">{{ t('settings.bot.outside_hours_message') }}</span>
            <textarea v-model="form.outside_hours_message" rows="2" dir="auto" maxlength="1000" class="rounded-md border border-input bg-background px-3 py-2 text-sm" />
        </label>

        <div class="grid gap-1">
            <span class="text-xs font-medium">{{ t('settings.bot.handover_keywords') }}</span>
            <ChipsInput v-model="form.handover_keywords" :label="t('settings.bot.handover_keywords')" :placeholder="t('settings.bot.keyword_placeholder')" />
        </div>

        <div class="grid gap-1">
            <span class="text-xs font-medium">{{ t('settings.bot.spam_phrases') }}</span>
            <ChipsInput v-model="form.spam_phrases" :label="t('settings.bot.spam_phrases')" :placeholder="t('settings.bot.keyword_placeholder')" />
        </div>

        <div class="grid gap-1">
            <span class="text-xs font-medium">{{ t('settings.bot.low_value_phrases') }}</span>
            <ChipsInput v-model="form.low_value_phrases" :label="t('settings.bot.low_value_phrases')" :placeholder="t('settings.bot.keyword_placeholder')" />
        </div>

        <div class="grid gap-1">
            <span class="text-xs font-medium">{{ t('settings.bot.allowed_link_domains') }}</span>
            <ChipsInput v-model="form.allowed_link_domains" :label="t('settings.bot.allowed_link_domains')" placeholder="example.com" />
        </div>

        <div class="grid gap-3 sm:grid-cols-4">
            <label class="grid gap-1">
                <span class="text-xs font-medium">{{ t('settings.bot.max_turns') }}</span>
                <input v-model.number="form.max_bot_turns" type="number" min="1" max="50" :class="input" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs font-medium">{{ t('settings.bot.min_confidence') }}</span>
                <input v-model.number="form.min_confidence" type="number" min="0" max="1" step="0.05" :class="input" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs font-medium">{{ t('settings.bot.spam_repeat_threshold') }}</span>
                <input v-model.number="form.spam_repeat_threshold" type="number" min="1" max="50" :class="input" />
            </label>
            <fieldset class="grid gap-1">
                <legend class="mb-1 text-xs font-medium">{{ t('settings.bot.comment_delay') }}</legend>
                <div class="flex gap-2">
                    <input v-model.number="form.comment_reply_delay_min" type="number" min="0" max="600" :class="input" :aria-label="t('settings.bot.delay_min')" />
                    <input v-model.number="form.comment_reply_delay_max" type="number" :min="form.comment_reply_delay_min" max="600" :class="input" :aria-label="t('settings.bot.delay_max')" />
                </div>
            </fieldset>
        </div>

        <label class="grid gap-1">
            <span class="text-xs font-medium">{{ t('settings.bot.system_prompt') }}</span>
            <textarea v-model="form.system_prompt" rows="6" dir="auto" maxlength="10000" class="rounded-md border border-input bg-background px-3 py-2 font-mono text-xs" :disabled="!canEditAi" />
            <span v-if="!canEditAi" class="text-2xs text-muted-foreground">{{ t('settings.bot.ai_admin_only') }}</span>
        </label>

        <p v-if="error" role="alert" class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">{{ error }}</p>
        <button type="submit" class="inline-flex h-9 items-center gap-1.5 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground disabled:opacity-50" :disabled="busy">
            <LoaderCircle v-if="busy" class="size-4 animate-spin" aria-hidden="true" />{{ t('common.save') }}
        </button>
    </form>
</template>
