<script setup lang="ts">
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { formatNumber } from '@/i18n';
import type { SharedData } from '@/types';
import type { CommentIntent, RuleScope, RuleTestResult } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';
import { FlaskConical, LoaderCircle } from 'lucide-vue-next';
import { ref } from 'vue';

const { t, locale } = useI18n();
const api = useApi();
const page = usePage<SharedData>();

const text = ref('');
const scope = ref<Exclude<RuleScope, 'both'>>('message');
const platform = ref<PlatformValue>('facebook');
const busy = ref(false);
const result = ref<RuleTestResult | null>(null);
const error = ref<string | null>(null);

// Uses RuleEngine::peek on the server, so testing never counts a hit.
async function run(): Promise<void> {
    if (!text.value.trim()) return;
    busy.value = true;
    error.value = null;
    try {
        const { data } = await api.post<RuleTestResult>('/settings/bot/test', { text: text.value, scope: scope.value, platform: platform.value });
        result.value = data;
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        busy.value = false;
    }
}

const select = 'h-8 rounded-md border border-input bg-background px-2 text-xs';

const KNOWN_INTENTS: CommentIntent[] = ['buy', 'question', 'complaint', 'spam', 'other'];

function intentLabel(intent: string | undefined): string {
    if (!intent) return '';
    return (KNOWN_INTENTS as string[]).includes(intent) ? t(`comments.intent.${intent}`) : intent;
}
</script>

<template>
    <section class="space-y-3 rounded-lg border bg-card p-4 text-xs">
        <h2 class="flex items-center gap-1.5 text-sm font-medium"><FlaskConical class="size-4" aria-hidden="true" />{{ t('settings.tester.title') }}</h2>
        <form class="space-y-2" @submit.prevent="run">
            <textarea v-model="text" rows="3" dir="auto" maxlength="2000" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm" :placeholder="t('settings.tester.text')" :aria-label="t('settings.tester.text')" />
            <div class="flex flex-wrap gap-2">
                <select v-model="scope" :class="select" :aria-label="t('settings.rules.scope_label')">
                    <option value="message">{{ t('settings.rules.scope.message') }}</option>
                    <option value="comment">{{ t('settings.rules.scope.comment') }}</option>
                </select>
                <select v-model="platform" :class="select" :aria-label="t('ui.platforms')">
                    <option v-for="p in page.props.platforms" :key="p.value" :value="p.value">{{ p.label }}</option>
                </select>
                <button type="submit" class="inline-flex h-8 items-center gap-1 rounded-md bg-primary px-3 font-medium text-primary-foreground disabled:opacity-50" :disabled="busy || !text.trim()">
                    <LoaderCircle v-if="busy" class="size-3.5 animate-spin" aria-hidden="true" />{{ t('settings.tester.run') }}
                </button>
            </div>
        </form>

        <p v-if="error" role="alert" class="rounded-md bg-red-50 px-3 py-2 text-red-700">{{ error }}</p>

        <dl v-if="result" class="space-y-2" aria-live="polite">
            <div>
                <dt class="text-muted-foreground">{{ t('settings.tester.matched') }}</dt>
                <dd v-if="result.rule" class="mt-0.5 rounded-md bg-emerald-50 px-2 py-1 text-emerald-800">
                    <span class="font-medium">{{ result.rule.name }}</span> · {{ t(`settings.rules.action.${result.rule.action}`) }}
                    <span v-if="result.rule.public_replies?.length" class="mt-0.5 block" dir="auto">{{ result.rule.public_replies[0] }}</span>
                </dd>
                <dd v-else class="mt-0.5 font-medium">{{ t('settings.tester.no_match') }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ t('settings.tester.normalized') }}</dt>
                <dd class="mt-0.5 rounded bg-muted px-2 py-1" dir="auto">{{ result.normalized }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ t('settings.tester.ai') }}</dt>
                <dd v-if="result.ai_classification.error" class="mt-0.5 text-red-700" dir="ltr">{{ result.ai_classification.error }}</dd>
                <dd v-else class="mt-0.5 flex flex-wrap gap-x-3">
                    <span>{{ t('settings.tester.intent') }}: <b>{{ intentLabel(result.ai_classification.intent) }}</b></span>
                    <span>{{ t('settings.tester.confidence') }}: <b class="tabular-nums">{{ formatNumber(locale, result.ai_classification.confidence ?? 0, { style: 'percent' }) }}</b></span>
                    <span>{{ t('settings.tester.needs_human') }}: <b>{{ result.ai_classification.needs_human ? t('ui.yes') : t('ui.no') }}</b></span>
                    <span v-if="result.ai_classification.model">{{ t('settings.tester.model') }}: <b dir="ltr">{{ result.ai_classification.model }}</b></span>
                </dd>
            </div>
        </dl>
    </section>
</template>
