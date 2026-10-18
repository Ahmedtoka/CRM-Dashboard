<script setup lang="ts">
import ChipsInput from '@/components/crm/ChipsInput.vue';
import SettingsPanel from '@/components/crm/settings/SettingsPanel.vue';
import SettingsRow from '@/components/crm/settings/SettingsRow.vue';
import StickySaveBar from '@/components/crm/StickySaveBar.vue';
import ToggleSwitch from '@/components/crm/ToggleSwitch.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import type { BotSettings } from '@/types/admin';
import { router } from '@inertiajs/vue3';
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
        non_returnable_keywords: [...(s.non_returnable_keywords ?? [])],
        spam_repeat_threshold: s.spam_repeat_threshold,
        system_prompt: s.system_prompt ?? '',
        burst_wait_seconds: s.burst_wait_seconds,
        burst_max_wait_seconds: s.burst_max_wait_seconds,
        typing_ms_per_char: s.typing_ms_per_char,
        order_lookup_enabled: s.order_lookup_enabled,
        store_url: s.store_url ?? '',
    };
}

const form = reactive(fromProps(props.settings));
const dirty = computed(() => JSON.stringify(form) !== JSON.stringify(fromProps(props.settings)));
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
        non_returnable_keywords: form.non_returnable_keywords,
        spam_repeat_threshold: form.spam_repeat_threshold,
        burst_wait_seconds: form.burst_wait_seconds,
        burst_max_wait_seconds: form.burst_max_wait_seconds,
        typing_ms_per_char: form.typing_ms_per_char,
        order_lookup_enabled: form.order_lookup_enabled,
        store_url: form.store_url.trim() || null,
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

// DESIGN §3 field: 40px tap target, strong line, mauve focus ring.
const field =
    'h-10 rounded-md border border-line-strong bg-card px-3 text-sm focus-visible:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40';
const num = `${field} w-24 tabular-nums`;
const area =
    'w-full rounded-md border border-line-strong bg-card px-3 py-2 text-sm focus-visible:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 disabled:opacity-60';
</script>

<template>
    <form class="min-w-0 space-y-4" @submit.prevent="submit">
        <!-- Running -->
        <SettingsPanel :title="t('settings.bot.cards.run')" :description="t('settings.bot.cards.run_hint')">
            <SettingsRow :title="t('settings.bot.enabled')" :description="t('settings.bot.cards.enabled_hint')">
                <ToggleSwitch v-model="form.enabled" :label="t('settings.bot.enabled')" />
            </SettingsRow>
            <SettingsRow :title="t('settings.bot.order_lookup')" :description="t('settings.bot.order_lookup_hint')">
                <ToggleSwitch v-model="form.order_lookup_enabled" :label="t('settings.bot.order_lookup')" />
            </SettingsRow>
            <SettingsRow for="bot-max-turns" :title="t('settings.bot.max_turns')" :description="t('settings.bot.cards.max_turns_hint')">
                <input id="bot-max-turns" v-model.number="form.max_bot_turns" type="number" min="1" max="50" dir="ltr" :class="num" />
            </SettingsRow>
            <!-- The products menu's «تسوقي من الموقع» button (2026-09-19). -->
            <SettingsRow for="bot-store-url" :title="t('settings.bot.store_url')" :description="t('settings.bot.cards.store_url_hint')">
                <input
                    id="bot-store-url"
                    v-model="form.store_url"
                    type="url"
                    dir="ltr"
                    maxlength="255"
                    placeholder="https://levoilestores.com/"
                    :class="[field, 'w-full sm:w-72']"
                />
            </SettingsRow>
        </SettingsPanel>

        <!-- Timing -->
        <SettingsPanel :title="t('settings.bot.timing')" :description="t('settings.bot.cards.timing_hint')">
            <SettingsRow for="bot-burst-wait" :title="t('settings.bot.burst_wait')" :description="t('settings.bot.burst_wait_hint')">
                <input id="bot-burst-wait" v-model.number="form.burst_wait_seconds" type="number" min="0" max="60" step="1" dir="ltr" :class="num" />
            </SettingsRow>
            <SettingsRow for="bot-burst-max" :title="t('settings.bot.burst_max_wait')" :description="t('settings.bot.burst_max_wait_hint')">
                <input
                    id="bot-burst-max"
                    v-model.number="form.burst_max_wait_seconds"
                    type="number"
                    :min="form.burst_wait_seconds || 0"
                    max="120"
                    step="1"
                    dir="ltr"
                    :class="num"
                />
            </SettingsRow>
            <SettingsRow for="bot-typing" :title="t('settings.bot.typing_ms_per_char')" :description="t('settings.bot.typing_hint')">
                <input id="bot-typing" v-model.number="form.typing_ms_per_char" type="number" min="0" max="120" step="5" dir="ltr" :class="num" />
            </SettingsRow>
            <SettingsRow :title="t('settings.bot.comment_delay')" :description="t('settings.bot.cards.comment_delay_hint')">
                <div class="flex items-center gap-2" role="group" :aria-label="t('settings.bot.comment_delay')">
                    <input
                        v-model.number="form.comment_reply_delay_min"
                        type="number"
                        min="0"
                        max="600"
                        dir="ltr"
                        :class="num"
                        :aria-label="t('settings.bot.delay_min')"
                    />
                    <span class="text-13 text-fg-3" aria-hidden="true">–</span>
                    <input
                        v-model.number="form.comment_reply_delay_max"
                        type="number"
                        :min="form.comment_reply_delay_min"
                        max="600"
                        dir="ltr"
                        :class="num"
                        :aria-label="t('settings.bot.delay_max')"
                    />
                </div>
            </SettingsRow>
        </SettingsPanel>

        <!-- Working hours -->
        <SettingsPanel :title="t('settings.bot.cards.hours')" :description="t('settings.bot.cards.hours_hint')">
            <SettingsRow :title="t('settings.bot.working_hours')">
                <label class="flex items-center gap-2 text-sm">
                    {{ t('settings.bot.from') }}
                    <input v-model="form.from" type="time" dir="ltr" :class="[field, 'tabular-nums']" />
                </label>
                <label class="flex items-center gap-2 text-sm">
                    {{ t('settings.bot.to') }}
                    <input v-model="form.to" type="time" dir="ltr" :class="[field, 'tabular-nums']" />
                </label>
            </SettingsRow>
            <SettingsRow :title="t('settings.bot.days')" stack>
                <div class="flex flex-wrap gap-1.5 text-13" role="group" :aria-label="t('settings.bot.days')">
                    <label
                        v-for="(name, day) in dayNames"
                        :key="day"
                        class="inline-flex h-10 cursor-pointer items-center gap-1.5 rounded-md border border-line-strong px-3 font-medium transition-colors has-[:checked]:border-brand has-[:checked]:bg-brand-soft has-[:checked]:text-brand-ink"
                    >
                        <input
                            type="checkbox"
                            class="rounded border-line-strong"
                            :checked="form.days.includes(day)"
                            @change="toggleDay(day, ($event.target as HTMLInputElement).checked)"
                        />{{ name }}
                    </label>
                </div>
            </SettingsRow>
            <SettingsRow
                for="bot-outside-msg"
                :title="t('settings.bot.outside_hours_message')"
                :description="t('settings.bot.cards.outside_hours_hint')"
                stack
            >
                <textarea id="bot-outside-msg" v-model="form.outside_hours_message" rows="2" dir="auto" maxlength="1000" :class="area" />
            </SettingsRow>
        </SettingsPanel>

        <!-- Words -->
        <SettingsPanel :title="t('settings.bot.cards.words')" :description="t('settings.bot.cards.words_hint')">
            <SettingsRow :title="t('settings.bot.handover_keywords')" :description="t('settings.bot.cards.handover_hint')" stack>
                <ChipsInput
                    v-model="form.handover_keywords"
                    class="w-full"
                    :label="t('settings.bot.handover_keywords')"
                    :placeholder="t('settings.bot.keyword_placeholder')"
                />
            </SettingsRow>
            <SettingsRow :title="t('settings.bot.spam_phrases')" :description="t('settings.bot.cards.spam_hint')" stack>
                <ChipsInput
                    v-model="form.spam_phrases"
                    class="w-full"
                    :label="t('settings.bot.spam_phrases')"
                    :placeholder="t('settings.bot.keyword_placeholder')"
                />
            </SettingsRow>
            <SettingsRow :title="t('settings.bot.low_value_phrases')" :description="t('settings.bot.cards.low_value_hint')" stack>
                <ChipsInput
                    v-model="form.low_value_phrases"
                    class="w-full"
                    :label="t('settings.bot.low_value_phrases')"
                    :placeholder="t('settings.bot.keyword_placeholder')"
                />
            </SettingsRow>
            <SettingsRow :title="t('settings.bot.non_returnable_keywords')" :description="t('settings.bot.cards.non_returnable_hint')" stack>
                <ChipsInput
                    v-model="form.non_returnable_keywords"
                    class="w-full"
                    :label="t('settings.bot.non_returnable_keywords')"
                    :placeholder="t('settings.bot.keyword_placeholder')"
                />
            </SettingsRow>
            <SettingsRow :title="t('settings.bot.allowed_link_domains')" :description="t('settings.bot.cards.domains_hint')" stack>
                <ChipsInput
                    v-model="form.allowed_link_domains"
                    class="w-full"
                    :label="t('settings.bot.allowed_link_domains')"
                    placeholder="example.com"
                />
            </SettingsRow>
            <SettingsRow
                for="bot-spam-repeat"
                :title="t('settings.bot.spam_repeat_threshold')"
                :description="t('settings.bot.cards.spam_repeat_hint')"
            >
                <input id="bot-spam-repeat" v-model.number="form.spam_repeat_threshold" type="number" min="1" max="50" dir="ltr" :class="num" />
            </SettingsRow>
        </SettingsPanel>

        <!-- AI -->
        <SettingsPanel :title="t('settings.bot.cards.ai')" :description="t('settings.bot.cards.ai_hint')">
            <SettingsRow
                :title="t('settings.bot.ai_enabled')"
                :description="canEditAi ? t('settings.bot.cards.ai_enabled_hint') : t('settings.bot.ai_admin_only')"
            >
                <ToggleSwitch v-model="form.ai_enabled" :label="t('settings.bot.ai_enabled')" :disabled="!canEditAi" />
            </SettingsRow>
            <SettingsRow
                for="bot-min-confidence"
                :title="t('settings.bot.min_confidence')"
                :description="t('settings.bot.cards.min_confidence_hint')"
            >
                <input
                    id="bot-min-confidence"
                    v-model.number="form.min_confidence"
                    type="number"
                    min="0"
                    max="1"
                    step="0.05"
                    dir="ltr"
                    :class="num"
                />
            </SettingsRow>
            <SettingsRow
                for="bot-system-prompt"
                :title="t('settings.bot.system_prompt')"
                :description="canEditAi ? t('settings.bot.cards.system_prompt_hint') : t('settings.bot.ai_admin_only')"
                stack
            >
                <textarea
                    id="bot-system-prompt"
                    v-model="form.system_prompt"
                    rows="6"
                    dir="auto"
                    maxlength="10000"
                    :class="area"
                    :disabled="!canEditAi"
                />
            </SettingsRow>
        </SettingsPanel>

        <p v-if="error" role="alert" class="rounded-md bg-danger-soft px-3 py-2 text-13 text-danger">{{ error }}</p>
        <StickySaveBar :busy="busy" :dirty="dirty" :bleed="false" submit />
    </form>
</template>
