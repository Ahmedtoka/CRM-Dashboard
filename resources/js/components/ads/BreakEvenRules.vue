<script setup lang="ts">
/**
 * The S5 half of «الإعداد › القواعد» (spec 7.1, 7.4): break-even inputs (global + per account), targets, low-stock units,
 * spike floor and the notifications switch. S2's `Ads/SetupRules` page renders it from its `rules` prop.
 */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { parseTypedNumber } from '@/lib/format';
import type { BreakEvenInputs, RulesSetupAccount, RulesSetupProps } from '@/types/ads';
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps<RulesSetupProps>();
const { t } = useI18n();
const toast = useToast();
const page = usePage();

const FIELDS = ['margin_pct', 'shipping_subsidy', 'return_cost', 'target_cpp', 'target_cpo'] as const;
const GENERAL = ['low_stock_units', 'spike_min_amount'] as const;
type Field = (typeof FIELDS)[number];
type GeneralField = (typeof GENERAL)[number];
type Draft = Record<Field, string>;
type Errors = Partial<Record<Field | GeneralField, string>>;

const toDraft = (v: BreakEvenInputs): Draft => Object.fromEntries(FIELDS.map((f) => [f, v[f] === null ? '' : String(v[f])])) as Draft;
const globalDraft = reactive<Draft>(toDraft(props.global));
const accountDrafts = reactive<Record<number, Draft>>(Object.fromEntries(props.accounts.map((a) => [a.id, toDraft(a.inputs)])));
const general = reactive<Record<GeneralField, string>>({
    low_stock_units: String(props.general.low_stock_units),
    spike_min_amount: String(props.general.spike_min_amount),
});
const saving = ref<string | null>(null);
/** The form the last save came from: server errors (usePage().props.errors) show under that form only. */
const lastSaved = ref<string | null>(null);
const clientErrors = reactive<Record<string, Errors>>({});

/** Client rules mirror the server (RulesSetupController::update): margin above 0 up to 100, costs >= 0, targets above 0. */
function check(f: Field | GeneralField, raw: string): { value: number | null; error?: string } {
    const v = parseTypedNumber(raw);
    const required = f === 'low_stock_units' || f === 'spike_min_amount';
    if (v === null) return required ? { value: null, error: t('ads.rules_setup.errors.number') } : { value: null };
    if (Number.isNaN(v)) return { value: null, error: t('ads.rules_setup.errors.number') };
    if (f === 'margin_pct' && (v <= 0 || v > 100)) return { value: v, error: t('ads.rules_setup.errors.margin') };
    if ((f === 'target_cpp' || f === 'target_cpo') && v <= 0) return { value: v, error: t('ads.rules_setup.errors.positive') };
    if (v < 0) return { value: v, error: t('ads.rules_setup.errors.not_negative') };
    if (f === 'low_stock_units' && !Number.isInteger(v)) return { value: v, error: t('ads.rules_setup.errors.whole') };

    return { value: v };
}

function validate<K extends Field | GeneralField>(key: string, fields: readonly K[], d: Record<K, string>): Record<K, number | null> | null {
    const out = {} as Record<K, number | null>;
    const errs: Errors = {};
    for (const f of fields) {
        const r = check(f, d[f]);
        if (r.error) errs[f] = r.error;
        out[f] = r.value;
    }
    clientErrors[key] = errs;

    return Object.keys(errs).length ? null : out;
}

const serverErrors = computed(() => (page.props.errors ?? {}) as Record<string, string>);
const errorFor = (key: string, f: Field | GeneralField): string | undefined =>
    clientErrors[key]?.[f] ?? (lastSaved.value === key ? serverErrors.value[f] : undefined);

function save(key: string, data: Record<string, unknown>): void {
    saving.value = key;
    lastSaved.value = key;
    router.put('/ads/setup/rules', data as never, {
        preserveScroll: true,
        onSuccess: () => toast.push(t('ads.rules_setup.saved')),
        onFinish: () => (saving.value = null),
    });
}
function saveGlobal(): void {
    const v = validate('global', FIELDS, globalDraft);
    if (v) save('global', { account_id: null, ...v });
}
function saveAccount(a: RulesSetupAccount): void {
    const key = `account-${a.id}`;
    const v = validate(key, FIELDS, accountDrafts[a.id]);
    if (v) save(key, { account_id: a.id, ...v });
}
function saveGeneral(): void {
    const v = validate('general', GENERAL, general);
    if (v) save('general', v);
}

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
    return v === null
        ? t('ads.rules_setup.target_from.none')
        : `${Math.round(v)} · ${t(`ads.rules_setup.target_from.${a.targets[`${kind}_source`]}`)}`;
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
                        :aria-invalid="errorFor('global', f) ? 'true' : undefined"
                    />
                    <p v-if="errorFor('global', f)" class="text-2xs text-destructive" role="alert" :data-test="`error-global-${f}`">
                        {{ errorFor('global', f) }}
                    </p>
                </label>
            </div>
            <Button v-if="can_edit" size="sm" :disabled="saving === 'global'" data-test="save-global" @click="saveGlobal">{{
                t('ads.rules_setup.save')
            }}</Button>
        </section>

        <section class="space-y-3 rounded-lg bg-card p-4 shadow-card" aria-labelledby="accounts-title">
            <div>
                <h2 id="accounts-title" class="text-sm font-bold">{{ t('ads.rules_setup.accounts') }}</h2>
                <p class="text-xs text-muted-foreground">{{ t('ads.rules_setup.account_hint') }}</p>
            </div>
            <article v-for="a in accounts" :key="a.id" class="space-y-2 border-t pt-3 first:border-t-0 first:pt-0" :data-test="`account-${a.id}`">
                <header class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold" dir="auto">
                        <bdi>{{ a.name }}</bdi>
                    </h3>
                    <p class="flex items-center gap-2 text-xs">
                        <span>{{ t('ads.rules_setup.floor') }}</span>
                        <strong class="tabular-nums" data-test="floor">{{ a.effective.floor.toFixed(2) }}</strong>
                        <span v-if="a.effective.is_default" class="rounded-full bg-muted px-2 py-0.5" data-test="floor-default">{{
                            t('ads.rules_setup.floor_default')
                        }}</span>
                        <span v-if="a.effective.unprofitable" class="text-destructive">{{ t('ads.rules_setup.floor_unprofitable') }}</span>
                    </p>
                </header>
                <!-- The full D12 sentence, the same one the alert cards stand on. -->
                <p v-if="a.effective.is_default" class="text-2xs text-muted-foreground" data-test="floor-default-sentence">
                    {{ t('ads.alerts.floor_default', { floor: default_floor }) }}
                </p>
                <p class="text-2xs text-muted-foreground">
                    {{
                        t('ads.rules_setup.measured', {
                            aov: a.effective.aov === null ? '-' : Math.round(a.effective.aov),
                            refusal: Math.round(a.effective.refusal_rate * 100),
                        })
                    }}
                    · {{ t('ads.rules_setup.fields.target_cpp') }}: {{ targetHint(a, 'cpp') }} · {{ t('ads.rules_setup.fields.target_cpo') }}:
                    {{ targetHint(a, 'cpo') }}
                </p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <label v-for="f in FIELDS" :key="f" class="space-y-1 text-xs">
                        <span class="block text-muted-foreground">{{ t(`ads.rules_setup.fields.${f}`) }}</span>
                        <Input
                            v-model="accountDrafts[a.id][f]"
                            inputmode="decimal"
                            dir="ltr"
                            :placeholder="placeholder(f)"
                            :disabled="!can_edit"
                            :aria-invalid="errorFor(`account-${a.id}`, f) ? 'true' : undefined"
                        />
                        <p v-if="errorFor(`account-${a.id}`, f)" class="text-2xs text-destructive" role="alert">
                            {{ errorFor(`account-${a.id}`, f) }}
                        </p>
                    </label>
                </div>
                <Button v-if="can_edit" size="sm" variant="outline" :disabled="saving === `account-${a.id}`" @click="saveAccount(a)">{{
                    t('ads.rules_setup.save')
                }}</Button>
            </article>
        </section>

        <section class="space-y-3 rounded-lg bg-card p-4 shadow-card" aria-labelledby="general-title">
            <h2 id="general-title" class="text-sm font-bold">{{ t('ads.rules_setup.general') }}</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="space-y-1 text-xs">
                    <span class="block text-muted-foreground">{{ t('ads.rules_setup.fields.low_stock_units') }}</span>
                    <Input v-model="general.low_stock_units" inputmode="numeric" dir="ltr" :disabled="!can_edit" />
                    <p v-if="errorFor('general', 'low_stock_units')" class="text-2xs text-destructive" role="alert">
                        {{ errorFor('general', 'low_stock_units') }}
                    </p>
                </label>
                <label class="space-y-1 text-xs">
                    <span class="block text-muted-foreground">{{ t('ads.rules_setup.fields.spike_min_amount') }}</span>
                    <Input v-model="general.spike_min_amount" inputmode="decimal" dir="ltr" :disabled="!can_edit" />
                    <p v-if="errorFor('general', 'spike_min_amount')" class="text-2xs text-destructive" role="alert">
                        {{ errorFor('general', 'spike_min_amount') }}
                    </p>
                </label>
            </div>
            <Button v-if="can_edit" size="sm" variant="outline" :disabled="saving === 'general'" @click="saveGeneral">{{
                t('ads.rules_setup.save')
            }}</Button>
        </section>
    </div>
</template>
