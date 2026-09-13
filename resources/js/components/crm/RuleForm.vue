<script setup lang="ts">
import ChipsInput from '@/components/crm/ChipsInput.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import PlatformCheckboxes from '@/components/crm/PlatformCheckboxes.vue';
import { useI18n } from '@/composables/useI18n';
import type { BotRule, BotRuleInput, RuleAction, RuleMatchType, RuleScope } from '@/types/admin';
import { Plus, X } from 'lucide-vue-next';
import { reactive, watch } from 'vue';

const props = defineProps<{ open: boolean; rule: BotRule | null; busy: boolean; error: string | null }>();
const emit = defineEmits<{ 'update:open': [open: boolean]; submit: [id: number | null, payload: BotRuleInput] }>();

const { t } = useI18n();

const SCOPES: RuleScope[] = ['message', 'comment', 'both'];
const MATCHES: RuleMatchType[] = ['any_keyword', 'all_keywords', 'exact', 'regex'];
const ACTIONS: RuleAction[] = ['reply', 'reply_and_handover', 'handover', 'hide'];

function blank(): BotRuleInput {
    return { name: '', is_active: true, priority: 50, scope: 'both', platforms: [], match_type: 'any_keyword', keywords: [], public_replies: [''], private_reply: '', action: 'reply' };
}

const form = reactive<BotRuleInput>(blank());

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const r = props.rule;
        Object.assign(
            form,
            r
                ? { ...r, platforms: [...(r.platforms ?? [])], keywords: [...r.keywords], public_replies: r.public_replies?.length ? [...r.public_replies] : [''], private_reply: r.private_reply ?? '' }
                : blank(),
        );
    },
    { immediate: true },
);

function submit(): void {
    const payload: BotRuleInput = {
        name: form.name,
        is_active: form.is_active,
        priority: Number(form.priority) || 0,
        scope: form.scope,
        platforms: form.platforms?.length ? form.platforms : null,
        match_type: form.match_type,
        keywords: form.keywords,
        public_replies: (form.public_replies ?? []).map((v) => v.trim()).filter(Boolean),
        private_reply: form.private_reply?.trim() || null,
        action: form.action,
    };
    emit('submit', props.rule?.id ?? null, payload);
}

const input = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';
</script>

<template>
    <FormDialog :open="open" wide :title="rule ? t('settings.bot.edit_rule') : t('settings.bot.add_rule')" :busy="busy" :error="error" @update:open="emit('update:open', $event)" @submit="submit">
        <div class="grid gap-3 sm:grid-cols-[1fr_7rem]">
            <label class="grid gap-1">
                <span class="text-xs font-medium">{{ t('settings.rules.name') }}</span>
                <input v-model="form.name" :class="input" required maxlength="255" dir="auto" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs font-medium">{{ t('settings.rules.priority') }}</span>
                <input v-model.number="form.priority" type="number" min="0" max="100000" :class="input" :title="t('settings.rules.priority_hint')" />
            </label>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <label class="grid gap-1">
                <span class="text-xs font-medium">{{ t('settings.rules.scope_label') }}</span>
                <select v-model="form.scope" :class="input">
                    <option v-for="s in SCOPES" :key="s" :value="s">{{ t(`settings.rules.scope.${s}`) }}</option>
                </select>
            </label>
            <label class="grid gap-1">
                <span class="text-xs font-medium">{{ t('settings.rules.match_label') }}</span>
                <select v-model="form.match_type" :class="input">
                    <option v-for="m in MATCHES" :key="m" :value="m">{{ t(`settings.rules.match.${m}`) }}</option>
                </select>
            </label>
            <label class="grid gap-1">
                <span class="text-xs font-medium">{{ t('settings.rules.action_label') }}</span>
                <select v-model="form.action" :class="input">
                    <option v-for="a in ACTIONS" :key="a" :value="a">{{ t(`settings.rules.action.${a}`) }}</option>
                </select>
            </label>
        </div>

        <PlatformCheckboxes :model-value="form.platforms ?? []" :legend="t('settings.rules.platforms')" @update:model-value="form.platforms = $event" />

        <div class="grid gap-1">
            <span class="text-xs font-medium">{{ t('settings.rules.keywords') }}</span>
            <ChipsInput v-model="form.keywords" :label="t('settings.rules.keywords')" :placeholder="t('settings.bot.keyword_placeholder')" />
        </div>

        <fieldset class="grid gap-1.5">
            <legend class="mb-1 text-xs font-medium">{{ t('settings.rules.public_replies') }}</legend>
            <div v-for="(_, index) in form.public_replies ?? []" :key="index" class="flex items-start gap-1">
                <textarea v-model="form.public_replies![index]" rows="2" dir="auto" maxlength="2000" class="flex-1 rounded-md border border-input bg-background px-3 py-1.5 text-sm" :aria-label="t('settings.rules.variant', { n: index + 1 })" />
                <button type="button" class="rounded p-1.5 text-muted-foreground hover:bg-muted" :aria-label="t('ui.remove_item', { item: t('settings.rules.variant', { n: index + 1 }) })" @click="form.public_replies!.splice(index, 1)">
                    <X class="size-3.5" aria-hidden="true" />
                </button>
            </div>
            <button type="button" class="inline-flex items-center gap-1 justify-self-start text-xs text-primary hover:underline" @click="(form.public_replies ??= []).push('')">
                <Plus class="size-3.5" aria-hidden="true" />{{ t('settings.rules.add_variant') }}
            </button>
        </fieldset>

        <label class="grid gap-1">
            <span class="text-xs font-medium">{{ t('settings.rules.private_reply') }}</span>
            <textarea v-model="form.private_reply" rows="2" dir="auto" maxlength="2000" class="rounded-md border border-input bg-background px-3 py-1.5 text-sm" />
        </label>

        <label class="flex items-center gap-2 text-xs"><input v-model="form.is_active" type="checkbox" class="rounded border-input" />{{ t('settings.rules.active') }}</label>
    </FormDialog>
</template>
