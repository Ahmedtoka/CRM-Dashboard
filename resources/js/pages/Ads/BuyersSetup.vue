<script setup lang="ts">
/** Ads Hub — إعداد الميديا باير: buyers, monthly targets, tax rate and winner thresholds (spec §8.6). */
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import ToggleSwitch from '@/components/crm/ToggleSwitch.vue';
import { Button, buttonVariants } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { addDays, cairoToday } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';
import type { AdBuyerSetupRow, AdsBuyersSetupProps, AdsWinnerThresholds } from '@/types/ads';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, UserPlus } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps<AdsBuyersSetupProps>();

const { t, locale } = useI18n();
const toast = useToast();
const page = usePage<SharedData>();

const inputClass =
    'flex h-9 w-full rounded-md border border-input bg-card px-3 text-sm placeholder:text-muted-foreground focus-visible:border-primary focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-primary disabled:opacity-50';
const smallInput = 'h-8 w-28 rounded-md border border-input bg-card px-2 text-xs';
const outlineSm = cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'gap-1.5');
const DEFAULT_COLOR = '#1877F2';

const num = (value: number | string | null | undefined): string => (value === null || value === undefined ? '' : String(value));

/* ---- buyers table: toggle / delete via one action form ---- */
const action = useForm({});
const busyKey = ref<string | null>(null);
const busy = (key: string) => busyKey.value === key;

function toggleBuyer(b: AdBuyerSetupRow, value: boolean): void {
    busyKey.value = `toggle-${b.id}`;
    action
        .transform(() => ({ name: b.name, color: b.color, user_id: b.user?.id ?? null, is_active: value }))
        .put(`/ads/setup/buyers/${b.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.push(t('ads.setup.saved')),
            onError: (errors) => toast.push(String(Object.values(errors)[0] ?? t('common.error')), 'error'),
            onFinish: () => (busyKey.value = null),
        });
}

const deleting = ref<AdBuyerSetupRow | null>(null);
const deleteOpen = computed({ get: () => deleting.value !== null, set: (v) => !v && (deleting.value = null) });

function confirmDelete(): void {
    const b = deleting.value;
    if (!b) return;
    busyKey.value = `delete-${b.id}`;
    action
        .transform(() => ({}))
        .delete(`/ads/setup/buyers/${b.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                // A buyer with account history is archived, not deleted: it is still in the refreshed list.
                const stillThere = (page.props as unknown as AdsBuyersSetupProps).buyers.some((x) => x.id === b.id);
                toast.push(t(stillThere ? 'ads.setup.archived' : 'ads.setup.deleted'));
                deleting.value = null;
            },
            onError: (errors) => toast.push(String(Object.values(errors)[0] ?? t('common.error')), 'error'),
            onFinish: () => (busyKey.value = null),
        });
}

/* ---- add / edit buyer ---- */
const dialogOpen = ref(false);
const editing = ref<AdBuyerSetupRow | null>(null);
const buyerForm = useForm<{ name: string; color: string; user_id: string; is_active: boolean }>({
    name: '',
    color: DEFAULT_COLOR,
    user_id: '',
    is_active: true,
});

function openBuyer(b: AdBuyerSetupRow | null): void {
    editing.value = b;
    buyerForm
        .defaults({
            name: b?.name ?? '',
            color: b?.color || DEFAULT_COLOR,
            user_id: b?.user ? String(b.user.id) : '',
            is_active: b?.is_active ?? true,
        })
        .reset();
    buyerForm.clearErrors();
    dialogOpen.value = true;
}

function submitBuyer(): void {
    buyerForm.transform((d) => ({ ...d, user_id: d.user_id === '' ? null : Number(d.user_id) }));
    const done = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
            toast.push(t('ads.setup.saved'));
        },
    };
    if (editing.value) buyerForm.put(`/ads/setup/buyers/${editing.value.id}`, done);
    else buyerForm.post('/ads/setup/buyers', done);
}

/** A user can be linked to one buyer only: hide the ones taken by other buyers. */
const freeUsers = computed(() => {
    const taken = new Set(props.buyers.filter((b) => b.id !== editing.value?.id && b.user).map((b) => b.user!.id));

    return props.users.filter((u) => !taken.has(u.id));
});

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('ads.setup.col_buyer'), primary: true },
    { key: 'user', label: t('ads.setup.col_user') },
    { key: 'is_active', label: t('ads.setup.col_active'), align: 'center' },
    { key: 'actions', label: t('ads.setup.col_actions'), align: 'end' },
]);

/* ---- targets: previous, current and next month of the chosen buyer, one save per row ---- */
const selectedBuyerId = ref<number | null>(props.buyers[0]?.id ?? null);
watch(
    () => props.buyers,
    (buyers) => {
        if (!buyers.some((b) => b.id === selectedBuyerId.value)) selectedBuyerId.value = buyers[0]?.id ?? null;
    },
);
const selectedBuyer = computed(() => props.buyers.find((b) => b.id === selectedBuyerId.value) ?? null);

const currentMonth = cairoToday().slice(0, 7);
const monthRows = computed(() => {
    const first = `${currentMonth}-01`;

    return [
        { month: addDays(first, -1).slice(0, 7), label: t('ads.setup.month_previous') },
        { month: currentMonth, label: t('ads.setup.month_current') },
        { month: addDays(first, 32).slice(0, 7), label: t('ads.setup.month_next') },
    ];
});

const monthLabel = (month: string): string =>
    new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(
        new Date(`${month}-01T00:00:00Z`),
    );

/** `base*` = the saved values the draft was seeded from, so a reload after one row's save keeps the other rows' edits. */
const drafts = reactive<Record<string, { budget: string; roas: string; baseBudget: string; baseRoas: string }>>({});
const draftKey = (month: string) => `${selectedBuyerId.value}:${month}`;

watch(
    [selectedBuyer, monthRows],
    () => {
        const b = selectedBuyer.value;
        if (!b) return;
        for (const row of monthRows.value) {
            const saved = b.targets.find((x) => x.month === row.month);
            const budget = num(saved?.budget);
            const roas = num(saved?.target_roas);
            const d = drafts[draftKey(row.month)];
            const untouched = d !== undefined && String(d.budget) === d.baseBudget && String(d.roas) === d.baseRoas;
            const matchesSaved = d !== undefined && String(d.budget) === budget && String(d.roas) === roas;
            if (!d || untouched || matchesSaved) {
                drafts[draftKey(row.month)] = { budget, roas, baseBudget: budget, baseRoas: roas };
            } else {
                d.baseBudget = budget; // unsaved edit: keep it, only follow the new saved values
                d.baseRoas = roas;
            }
        }
    },
    { immediate: true },
);

const targetForm = useForm<{ month: string; budget: string; target_roas: string }>({ month: '', budget: '', target_roas: '' });
const savingMonth = ref<string | null>(null);
const targetError = (month: string): string | null =>
    savingMonth.value === month ? (targetForm.errors.budget ?? targetForm.errors.target_roas ?? targetForm.errors.month ?? null) : null;

function saveTarget(month: string): void {
    const b = selectedBuyer.value;
    const d = drafts[draftKey(month)];
    if (!b || !d) return;
    savingMonth.value = month;
    targetForm.month = month;
    targetForm.budget = d.budget === '' ? '0' : d.budget;
    targetForm.target_roas = d.roas;
    targetForm.clearErrors();
    targetForm.put(`/ads/setup/buyers/${b.id}/targets`, {
        preserveScroll: true,
        onSuccess: () => toast.push(t('ads.setup.saved')),
    });
}

/* ---- settings: tax rate + winner thresholds ---- */
const THRESHOLD_KEYS: (keyof AdsWinnerThresholds)[] = ['winner', 'promising', 'loser', 'loser_min_spend', 'min_spend', 'min_days'];
const settingsForm = useForm({
    tax_rate_percent: num(props.settings.tax_rate_percent),
    winner_thresholds: Object.fromEntries(THRESHOLD_KEYS.map((k) => [k, num(props.settings.winner_thresholds[k])])) as Record<
        keyof AdsWinnerThresholds,
        string
    >,
});
const settingsError = (key: string): string | undefined => (settingsForm.errors as Record<string, string | undefined>)[key];
const thresholdStep = (key: keyof AdsWinnerThresholds) => (key === 'min_days' ? '1' : key.endsWith('spend') ? '1' : '0.01');

function saveSettings(): void {
    settingsForm.put('/ads/setup/settings', {
        preserveScroll: true,
        onSuccess: () => toast.push(t('ads.setup.saved')),
    });
}
</script>

<template>
    <Head :title="t('ads.setup.title')" />

    <AppLayout>
        <div class="mx-auto w-full max-w-5xl space-y-8 p-4 md:p-6">
            <PageHeader :title="t('ads.setup.title')" :description="t('ads.setup.description')" />

            <!-- Buyers -->
            <section class="space-y-3" :aria-label="t('ads.setup.buyers_title')">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="flex-1 text-sm font-semibold">{{ t('ads.setup.buyers_title') }}</h2>
                    <Link href="/settings/users" :class="outlineSm"><UserPlus aria-hidden="true" />{{ t('ads.setup.add_user') }}</Link>
                    <button type="button" :class="buttonVariants({ variant: 'default', size: 'sm' })" @click="openBuyer(null)">
                        <Plus aria-hidden="true" />{{ t('ads.setup.add_buyer') }}
                    </button>
                </div>
                <DataTable table-id="ads-buyers-setup" :columns="columns" :rows="buyers" :caption="t('ads.setup.buyers_title')" :empty="t('ads.setup.no_buyers')">
                    <template #cell-name="{ row }">
                        <span class="inline-flex items-center gap-2 font-medium">
                            <span
                                class="size-3 rounded-full border border-border"
                                :style="{ backgroundColor: row.color || 'transparent' }"
                                aria-hidden="true"
                            />
                            {{ row.name }}
                        </span>
                    </template>
                    <template #cell-user="{ row }">{{ row.user?.name ?? '—' }}</template>
                    <template #cell-is_active="{ row }">
                        <ToggleSwitch
                            :model-value="row.is_active"
                            :label="t('ads.setup.active_label', { name: row.name })"
                            :disabled="busy(`toggle-${row.id}`)"
                            @update:model-value="toggleBuyer(row, $event)"
                        />
                    </template>
                    <template #cell-actions="{ row }">
                        <div class="flex justify-end gap-2">
                            <button type="button" :class="outlineSm" @click="openBuyer(row)">
                                <Pencil aria-hidden="true" />{{ t('ads.accounts.edit') }}
                            </button>
                            <button type="button" :class="cn(outlineSm, 'text-destructive')" @click="deleting = row">
                                <Trash2 aria-hidden="true" />{{ t('ads.accounts.delete') }}
                            </button>
                        </div>
                    </template>
                </DataTable>
            </section>

            <!-- Targets -->
            <section v-if="buyers.length" class="space-y-3 rounded-lg bg-card p-4 shadow-card" :aria-label="t('ads.setup.targets_title')">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm font-semibold">{{ t('ads.setup.targets_title') }}</h2>
                        <p class="text-xs text-muted-foreground">{{ t('ads.setup.targets_note') }}</p>
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-medium" for="target-buyer">{{ t('ads.setup.pick_buyer') }}</label>
                        <select id="target-buyer" v-model="selectedBuyerId" :class="cn(inputClass, 'w-48')">
                            <option v-for="b in buyers" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>
                </div>

                <ul class="divide-y divide-border/60">
                    <li v-for="row in monthRows" :key="row.month" class="flex flex-wrap items-end gap-3 py-3">
                        <div class="min-w-36 flex-1">
                            <p class="text-xs font-medium">{{ row.label }}</p>
                            <p class="text-2xs text-muted-foreground">{{ monthLabel(row.month) }}</p>
                        </div>
                        <div v-if="drafts[draftKey(row.month)]" class="flex flex-wrap items-end gap-3">
                            <div class="space-y-1">
                                <label class="text-2xs text-muted-foreground" :for="`budget-${row.month}`"
                                    >{{ t('ads.setup.budget') }} ({{ t('common.currency') }})</label
                                >
                                <input
                                    :id="`budget-${row.month}`"
                                    v-model="drafts[draftKey(row.month)].budget"
                                    type="number"
                                    min="0"
                                    step="1"
                                    inputmode="decimal"
                                    dir="ltr"
                                    :class="smallInput"
                                />
                            </div>
                            <div class="space-y-1">
                                <label class="text-2xs text-muted-foreground" :for="`roas-${row.month}`">{{ t('ads.setup.target_roas') }}</label>
                                <input
                                    :id="`roas-${row.month}`"
                                    v-model="drafts[draftKey(row.month)].roas"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputmode="decimal"
                                    dir="ltr"
                                    :class="smallInput"
                                />
                            </div>
                            <Button
                                size="sm"
                                @click="saveTarget(row.month)"
                                :loading="targetForm.processing && savingMonth === row.month"
                            >
                                {{
                                    t('ads.setup.save_row')
                                }}
                            </Button>
                        </div>
                        <p v-if="targetError(row.month)" role="alert" class="basis-full text-2xs text-destructive">{{ targetError(row.month) }}</p>
                    </li>
                </ul>
            </section>

            <!-- Settings -->
            <form class="space-y-5 rounded-lg bg-card p-4 shadow-card" :aria-label="t('ads.setup.settings_title')" @submit.prevent="saveSettings">
                <h2 class="text-sm font-semibold">{{ t('ads.setup.settings_title') }}</h2>

                <div class="max-w-xs space-y-1">
                    <label class="text-xs font-medium" for="tax-rate">{{ t('ads.setup.tax_rate') }} (%)</label>
                    <input
                        id="tax-rate"
                        v-model="settingsForm.tax_rate_percent"
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                        inputmode="decimal"
                        dir="ltr"
                        :class="inputClass"
                    />
                    <p class="text-2xs text-muted-foreground">{{ t('ads.setup.tax_help') }}</p>
                    <p v-if="settingsError('tax_rate_percent')" class="text-2xs text-destructive">{{ settingsError('tax_rate_percent') }}</p>
                </div>

                <div class="space-y-3">
                    <h3 class="text-xs font-semibold">{{ t('ads.setup.thresholds_title') }}</h3>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="key in THRESHOLD_KEYS" :key="key" class="space-y-1">
                            <label class="text-xs font-medium" :for="`th-${key}`">{{ t(`ads.setup.${key}`) }}</label>
                            <input
                                :id="`th-${key}`"
                                v-model="settingsForm.winner_thresholds[key]"
                                type="number"
                                :min="key === 'min_days' ? 1 : 0"
                                :max="key === 'min_days' ? 30 : undefined"
                                :step="thresholdStep(key)"
                                inputmode="decimal"
                                dir="ltr"
                                :class="inputClass"
                            />
                            <p class="text-2xs text-muted-foreground">{{ t(`ads.setup.${key}_help`) }}</p>
                            <p v-if="settingsError(`winner_thresholds.${key}`)" class="text-2xs text-destructive">
                                {{ settingsError(`winner_thresholds.${key}`) }}
                            </p>
                        </div>
                    </div>
                </div>

                <Button type="submit" :loading="settingsForm.processing">
                    {{ t('common.save') }}
                </Button>
            </form>
        </div>

        <FormDialog
            v-model:open="dialogOpen"
            :title="editing ? t('ads.setup.edit_buyer') : t('ads.setup.add_buyer')"
            :busy="buyerForm.processing"
            @submit="submitBuyer"
        >
            <div class="space-y-1">
                <label class="text-xs font-medium" for="buyer-name">{{ t('ads.setup.buyer_name') }}</label>
                <input id="buyer-name" v-model="buyerForm.name" type="text" maxlength="120" required :class="inputClass" />
                <p v-if="buyerForm.errors.name" class="text-2xs text-destructive">{{ buyerForm.errors.name }}</p>
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium" for="buyer-color">{{ t('ads.setup.color') }}</label>
                <input
                    id="buyer-color"
                    v-model="buyerForm.color"
                    type="color"
                    class="h-9 w-16 cursor-pointer rounded-md border border-input bg-card p-1"
                />
                <p v-if="buyerForm.errors.color" class="text-2xs text-destructive">{{ buyerForm.errors.color }}</p>
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium" for="buyer-user">{{ t('ads.setup.linked_user') }}</label>
                <select id="buyer-user" v-model="buyerForm.user_id" :class="inputClass">
                    <option value="">{{ t('ads.setup.no_user') }}</option>
                    <option v-for="u in freeUsers" :key="u.id" :value="String(u.id)">{{ u.name }}</option>
                </select>
                <p class="text-2xs text-muted-foreground">
                    {{ t('ads.setup.add_user_hint') }}
                    <Link href="/settings/users" class="text-primary underline-offset-2 hover:underline">{{ t('ads.setup.add_user') }}</Link>
                </p>
                <p v-if="buyerForm.errors.user_id" class="text-2xs text-destructive">{{ buyerForm.errors.user_id }}</p>
            </div>
            <div class="flex items-center gap-2">
                <ToggleSwitch v-model="buyerForm.is_active" :label="t('ads.setup.col_active')" />
                <span class="text-xs">{{ t('ads.setup.col_active') }}</span>
            </div>
        </FormDialog>

        <FormDialog
            v-model:open="deleteOpen"
            :title="t('ads.setup.delete_title')"
            :description="deleting ? t('ads.setup.delete_body', { name: deleting.name }) : undefined"
            :submit-label="t('ads.setup.delete_confirm')"
            :busy="deleting !== null && busy(`delete-${deleting.id}`)"
            destructive
            @submit="confirmDelete"
        />
    </AppLayout>
</template>
