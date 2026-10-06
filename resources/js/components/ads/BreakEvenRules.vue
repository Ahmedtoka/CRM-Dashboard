<script setup lang="ts">
/**
 * The S5 half of «الإعداد › القواعد» (spec 7.1, 7.4): break-even inputs (global + per account), targets, low-stock units,
 * spike floor and the notifications switch. S2's `Ads/SetupRules` page renders it from its `rules` prop.
 */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/composables/useI18n';
import type { BreakEvenInputs, RulesSetupAccount, RulesSetupProps } from '@/types/ads';
import { router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = defineProps<RulesSetupProps>();
const { t } = useI18n();

const FIELDS = ['margin_pct', 'shipping_subsidy', 'return_cost', 'target_cpp', 'target_cpo'] as const;
type Field = (typeof FIELDS)[number];
type Draft = Record<Field, string>;

const toDraft = (v: BreakEvenInputs): Draft => Object.fromEntries(FIELDS.map((f) => [f, v[f] === null ? '' : String(v[f])])) as Draft;
const globalDraft = reactive<Draft>(toDraft(props.global));
const accountDrafts = reactive<Record<number, Draft>>(Object.fromEntries(props.accounts.map((a) => [a.id, toDraft(a.inputs)])));
const general = reactive({ low_stock_units: String(props.general.low_stock_units), spike_min_amount: String(props.general.spike_min_amount) });
const saving = ref<string | null>(null);

const payload = (d: Draft): Record<Field, number | null> =>
    Object.fromEntries(FIELDS.map((f) => [f, d[f].trim() === '' ? null : Number(d[f])])) as Record<Field, number | null>;

function save(key: string, data: Record<string, unknown>): void {
    saving.value = key;
    router.put('/ads/setup/rules', data as never, { preserveScroll: true, onFinish: () => (saving.value = null) });
}
const saveGlobal = () => save('global', { account_id: null, ...payload(globalDraft) });
const saveAccount = (a: RulesSetupAccount) => save(`account-${a.id}`, { account_id: a.id, ...payload(accountDrafts[a.id]) });
const saveGeneral = () => save('general', { low_stock_units: Number(general.low_stock_units), spike_min_amount: Number(general.spike_min_amount) });

function toggleNotify(): void {
    saving.value = 'notify';
    router.put('/ads/setup/rules/notify', { enabled: !props.notify_enabled }, { preserveScroll: true, onFinish: () => (saving.value = null) });
}

const placeholder = (f: Field) => {
    const g = props.global[f];
    if (g !== null) return String(g);
    return f === 'target_cpp' || f === 'target_cpo' ? t('ads.rules_setup.median_hint') : '';
};
const targetHint = (a: RulesSetupAccount, kind: 'cpp' | 'cpo') => {
    const v = a.targets[kind];
    return v === null ? t('ads.rules_setup.target_from.none') : `${Math.round(v)} · ${t(`ads.rules_setup.target_from.${a.targets[`${kind}_source`]}`)}`;
};
</script>

<template>
    <div class="space-y-4" data-test="breakeven-rules">
        <p v-if="!can_edit" class="text-xs text-muted-foreground" data-test="read-only">{{ t('ads.rules_setup.read_only') }}</p>

        <section class="space-y-2 rounded-lg bg-card p-4 shadow-card" aria-labelledby="notify-title">
            <h2 id="notify-title" class="text-sm font-bold">{{ t('ads.rules_setup.notify') }}</h2>
            <p class="text-xs text-muted-foreground">{{ notify_enabled ? t('ads.rules_setup.notify_on') : t('ads.rules_setup.notify_off') }}</p>
            <Button
                v-if="can_edit"
                size="sm"
                role="switch"
                :aria-checked="notify_enabled"
                :variant="notify_enabled ? 'outline' : 'default'"
                :disabled="saving === 'notify'"
                data-test="notify-toggle"
                @click="toggleNotify"
            >
                {{ notify_enabled ? t('ads.rules_setup.notify_turn_off') : t('ads.rules_setup.notify_turn_on') }}
            </Button>
        </section>

        <section class="space-y-3 rounded-lg bg-card p-4 shadow-card" aria-labelledby="global-title">
            <h2 id="global-title" class="text-sm font-bold">{{ t('ads.rules_setup.global') }}</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <label v-for="f in FIELDS" :key="f" class="space-y-1 text-xs">
                    <span class="block text-muted-foreground">{{ t(`ads.rules_setup.fields.${f}`) }}</span>
                    <Input
                        v-model="globalDraft[f]"
                        inputmode="decimal"
                        dir="ltr"
                        :placeholder="f === 'target_cpp' || f === 'target_cpo' ? t('ads.rules_setup.median_hint') : ''"
                        :disabled="!can_edit"
                        :data-test="`global-${f}`"
                    />
                </label>
            </div>
            <Button v-if="can_edit" size="sm" :disabled="saving === 'global'" data-test="save-global" @click="saveGlobal">{{ t('ads.rules_setup.save') }}</Button>
        </section>

        <section class="space-y-3 rounded-lg bg-card p-4 shadow-card" aria-labelledby="accounts-title">
            <div>
                <h2 id="accounts-title" class="text-sm font-bold">{{ t('ads.rules_setup.accounts') }}</h2>
                <p class="text-xs text-muted-foreground">{{ t('ads.rules_setup.account_hint') }}</p>
            </div>
            <article v-for="a in accounts" :key="a.id" class="space-y-2 border-t pt-3 first:border-t-0 first:pt-0" :data-test="`account-${a.id}`">
                <header class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold" dir="auto"><bdi>{{ a.name }}</bdi></h3>
                    <p class="flex items-center gap-2 text-xs">
                        <span>{{ t('ads.rules_setup.floor') }}</span>
                        <strong class="tabular-nums" data-test="floor">{{ a.effective.floor.toFixed(2) }}</strong>
                        <span v-if="a.effective.is_default" class="rounded-full bg-muted px-2 py-0.5" data-test="floor-default">{{ t('ads.rules_setup.floor_default') }}</span>
                        <span v-if="a.effective.unprofitable" class="text-destructive">{{ t('ads.rules_setup.floor_unprofitable') }}</span>
                    </p>
                </header>
                <p class="text-2xs text-muted-foreground">
                    {{ t('ads.rules_setup.measured', { aov: a.effective.aov === null ? '-' : Math.round(a.effective.aov), refusal: Math.round(a.effective.refusal_rate * 100) }) }}
                    · {{ t('ads.rules_setup.fields.target_cpp') }}: {{ targetHint(a, 'cpp') }}
                    · {{ t('ads.rules_setup.fields.target_cpo') }}: {{ targetHint(a, 'cpo') }}
                </p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <label v-for="f in FIELDS" :key="f" class="space-y-1 text-xs">
                        <span class="block text-muted-foreground">{{ t(`ads.rules_setup.fields.${f}`) }}</span>
                        <Input v-model="accountDrafts[a.id][f]" inputmode="decimal" dir="ltr" :placeholder="placeholder(f)" :disabled="!can_edit" />
                    </label>
                </div>
                <Button v-if="can_edit" size="sm" variant="outline" :disabled="saving === `account-${a.id}`" @click="saveAccount(a)">{{ t('ads.rules_setup.save') }}</Button>
            </article>
        </section>

        <section class="space-y-3 rounded-lg bg-card p-4 shadow-card" aria-labelledby="general-title">
            <h2 id="general-title" class="text-sm font-bold">{{ t('ads.rules_setup.general') }}</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="space-y-1 text-xs">
                    <span class="block text-muted-foreground">{{ t('ads.rules_setup.fields.low_stock_units') }}</span>
                    <Input v-model="general.low_stock_units" inputmode="numeric" dir="ltr" :disabled="!can_edit" />
                </label>
                <label class="space-y-1 text-xs">
                    <span class="block text-muted-foreground">{{ t('ads.rules_setup.fields.spike_min_amount') }}</span>
                    <Input v-model="general.spike_min_amount" inputmode="decimal" dir="ltr" :disabled="!can_edit" />
                </label>
            </div>
            <Button v-if="can_edit" size="sm" variant="outline" :disabled="saving === 'general'" @click="saveGeneral">{{ t('ads.rules_setup.save') }}</Button>
        </section>
    </div>
</template>
