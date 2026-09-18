<script setup lang="ts">
import StatusChip from '@/components/crm/StatusChip.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import type { SharedData } from '@/types';
import type { BotPreviewResult } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';
import { Bot, ChevronDown, Send } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const { t } = useI18n();
const api = useApi();
const page = usePage<SharedData>();

const fallbackPlatforms: { value: PlatformValue; label: string }[] = [
    { value: 'facebook', label: 'Messenger' },
    { value: 'instagram', label: 'Instagram' },
    { value: 'whatsapp', label: 'WhatsApp' },
    { value: 'tiktok', label: 'TikTok' },
];
const platforms = computed(() => (page.props.platforms?.length ? page.props.platforms : fallbackPlatforms));

const text = ref('');
const platform = ref<PlatformValue>('instagram');
const busy = ref(false);
const error = ref<string | null>(null);
const result = ref<BotPreviewResult | null>(null);

async function ask(): Promise<void> {
    if (!text.value.trim() || busy.value) return;
    busy.value = true;
    error.value = null;
    try {
        const { data } = await api.post<BotPreviewResult>('/settings/bot-knowledge/ask', { text: text.value, platform: platform.value });
        result.value = data;
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="space-y-3 rounded-lg border border-border bg-card p-4 shadow-card">
        <div class="flex items-center gap-2">
            <Bot class="size-4 text-muted-foreground" aria-hidden="true" />
            <h2 class="text-sm font-semibold text-foreground">{{ t('settings.bot_knowledge.ask_title') }}</h2>
        </div>
        <p class="text-xs text-muted-foreground">{{ t('settings.bot_knowledge.ask_hint') }}</p>

        <form class="space-y-2" @submit.prevent="ask">
            <textarea
                v-model="text"
                dir="auto"
                rows="2"
                maxlength="1000"
                required
                :placeholder="t('settings.bot_knowledge.ask_placeholder')"
                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                @keydown.enter.ctrl.prevent="ask"
                @keydown.enter.meta.prevent="ask"
            />
            <div class="flex flex-wrap items-center gap-2">
                <label class="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                    {{ t('settings.bot_knowledge.platform') }}
                    <select v-model="platform" class="h-8 rounded-md border border-input bg-background px-2 text-xs text-foreground">
                        <option v-for="p in platforms" :key="p.value" :value="p.value">{{ p.label }}</option>
                    </select>
                </label>
                <button
                    type="submit"
                    class="ms-auto inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground disabled:opacity-50"
                    :disabled="busy || !text.trim()"
                >
                    <Send class="size-3.5 rtl:-scale-x-100" aria-hidden="true" />{{ t('settings.bot_knowledge.ask') }}
                </button>
            </div>
        </form>

        <p v-if="error" role="alert" class="text-xs text-destructive">{{ error }}</p>

        <div v-if="result" class="space-y-2 border-t border-border pt-3" aria-live="polite">
            <div class="flex flex-wrap items-center gap-1.5">
                <StatusChip v-if="result.would_handover" tone="warning" :label="t('settings.bot_knowledge.would_handover')" />
                <StatusChip v-else tone="positive" :label="t('settings.bot_knowledge.would_reply')" />
                <StatusChip v-if="result.reason" tone="neutral" :label="t(`settings.bot_knowledge.reasons.${result.reason}`)" />
                <StatusChip
                    v-if="result.intent"
                    tone="info"
                    :label="`${t('settings.bot_knowledge.intent')}: ${t(`settings.bot_knowledge.intents.${result.intent}`)}`"
                />
            </div>

            <p v-if="result.reply" dir="auto" class="max-w-prose whitespace-pre-line rounded-lg bg-muted px-3 py-2 text-sm text-foreground">{{ result.reply }}</p>

            <details class="group text-xs">
                <summary class="inline-flex cursor-pointer list-none items-center gap-1 text-muted-foreground hover:text-foreground">
                    <ChevronDown class="size-3.5 transition-transform group-open:rotate-180" aria-hidden="true" />
                    {{ t('settings.bot_knowledge.grounding') }} ({{ result.grounding.length }})
                </summary>
                <ul v-if="result.grounding.length" class="mt-2 space-y-1 ps-4">
                    <li v-for="(line, i) in result.grounding" :key="i" dir="auto" class="list-disc text-muted-foreground">{{ line }}</li>
                </ul>
                <p v-else class="mt-2 ps-4 text-muted-foreground">{{ t('settings.bot_knowledge.no_grounding') }}</p>
            </details>
        </div>
    </section>
</template>
