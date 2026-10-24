<script setup lang="ts">
/**
 * Stop / Run / budget-change / undo confirm (spec 4.5, U X6, round 3): the server's diff (current → new status, or the
 * daily budget before → after), today's spend and the data age. A Run or a budget change asks for the password when the
 * 15-minute window has passed (423 → password step). The reason travels with the proposal (the diff is bound to it), so
 * there is no reason field after proposing. When CRM_ADS_EXECUTE is off the proposal says so and the dialog shows the
 * dry-run banner («هيتعمل كذا»): confirming logs the change, nothing reaches the platform.
 */
import FormDialog from '@/components/crm/FormDialog.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { useWriteAction, type WriteType } from '@/composables/useWriteAction';
import { flowArrow, formatAdsMoney } from '@/lib/ads';
import { formatDateTime } from '@/lib/format';
import type { AdLevel } from '@/types/ads';
import { computed, nextTick, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        accountId: number;
        account: string;
        platform: string;
        level: AdLevel;
        externalId: string;
        name: string;
        /** set_status: the new status. Null for a budget change or an undo. */
        to?: 'active' | 'paused' | null;
        /** Round 3: set_budget changes the daily budget by `pct` percent. */
        type?: WriteType;
        pct?: number | null;
        /** Round 3 «تراجع»: the public id of the finished action to undo (the server proposes the inverse). */
        undoOf?: string | null;
        spendToday?: number | null;
        dataAt?: string | null;
        currency?: string;
        reason?: string;
        parentPaused?: boolean;
        /** `alert` from a decisions-feed card (S5): the server checks the alert is about this ad. */
        source?: 'ui' | 'alert';
        sourceRef?: string | null;
    }>(),
    { to: null, type: 'set_status', pct: null, undoOf: null, spendToday: null, dataAt: null, currency: 'EGP', reason: '', parentPaused: false, source: 'ui', sourceRef: null },
);
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ done: [status: 'active' | 'paused' | 'budget' | 'undo', outcome: { id: string; state: string; dryRun: boolean } | null] }>();

const { t, locale } = useI18n();
const toast = useToast();
const w = useWriteAction();
const password = ref('');
const budget = computed(() => props.type === 'set_budget');
const undo = computed(() => !!props.undoOf);
const stopping = computed(() => !budget.value && !undo.value && props.to === 'paused');
const destructive = computed(() => stopping.value || (budget.value && (props.pct ?? 0) < 0));
const target = () => ({
    accountId: props.accountId,
    level: props.level,
    externalId: props.externalId,
    type: props.type,
    to: props.to,
    pct: props.pct,
    reason: props.reason || null,
    source: props.source,
    sourceRef: props.sourceRef,
});

function start(): void {
    if (props.undoOf) void w.proposeUndo(props.undoOf, props.reason || null);
    else void w.propose(target());
}

watch(open, (o) => {
    if (o) {
        password.value = '';
        start();
    } else if (w.phase.value === 'review' || w.phase.value === 'reauth') {
        void w.cancel();
    }
});

const doneKind = () => (undo.value ? 'undo' : budget.value ? 'budget' : (props.to ?? 'paused'));

watch(w.phase, (p) => {
    if (p === 'done' || p === 'pending') {
        const fallback = t(undo.value ? 'ads.control.write.done_undo' : budget.value ? 'ads.control.write.done_budget' : stopping.value ? 'ads.control.write.done_stop' : 'ads.control.write.done_run');
        toast.push(w.message.value ?? fallback, w.outcome.value?.dryRun ? 'info' : p === 'done' ? 'success' : 'info');
        open.value = false;
        emit('done', doneKind(), w.outcome.value);
        w.reset();
    }
});

const statusLabel = (v: unknown) => {
    const s = String(v ?? '').toUpperCase();
    if (s === 'ACTIVE' || s === 'ENABLE') return t('ads.control.write.status_active');
    if (s === 'PAUSED' || s === 'DISABLE') return t('ads.control.write.status_paused');
    return t('ads.control.write.status_unknown');
};
const PATHS = ['daily_budget', 'parent_daily_budget', 'lifetime_budget'];
const pathLabel = (path: string) => (PATHS.includes(path) ? t(`ads.control.write.path.${path}`) : path);
/** A money cell is a number (major) or, for a budget change, {minor, currency}. */
const valueLabel = (v: unknown) => {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'number') return formatAdsMoney(v, locale.value, props.currency);
    if (typeof v === 'object' && v !== null && 'minor' in v) {
        const m = v as { minor: number; currency?: string };
        return formatAdsMoney(Number(m.minor) / 100, locale.value, m.currency ?? props.currency, 2);
    }
    return String(v);
};
const statusRow = computed(() => w.proposal.value?.diff.find((d) => d.path === 'status') ?? null);
const otherRows = computed(() => w.proposal.value?.diff.filter((d) => d.path !== 'status') ?? []);
const notes = computed(() => (w.proposal.value?.notes ?? []).filter((n) => n.key === 'learning_reentry'));
const dryRun = computed(() => w.proposal.value?.execute === false);
const pctLabel = computed(() => {
    const p = Number(w.proposal.value?.action.params?.pct ?? props.pct ?? 0);
    return p ? `${p > 0 ? '+' : ''}${p}%` : '';
});
const busy = computed(() => ['proposing', 'confirming'].includes(w.phase.value));
const title = computed(() => {
    if (undo.value) return t('ads.control.write.undo_title', { name: props.name });
    if (budget.value) return t('ads.control.write.budget_title', { name: props.name, pct: pctLabel.value });
    return t(stopping.value ? 'ads.control.write.stop_title' : 'ads.control.write.run_title', { name: props.name });
});
const submitLabel = computed(() => {
    if (w.phase.value === 'reauth') return t('ads.control.write.reauth_submit');
    if (w.phase.value === 'error') return t('ads.control.write.retry');
    if (undo.value) return t('ads.control.write.confirm_undo');
    if (budget.value) return t('ads.control.write.confirm_budget');
    return t(stopping.value ? 'ads.control.write.confirm_stop' : 'ads.control.write.confirm_run');
});

/* The password step takes the focus as soon as it shows. */
const passwordInput = ref<HTMLInputElement | null>(null);
watch(w.phase, async (p) => {
    if (p !== 'reauth') return;
    await nextTick();
    passwordInput.value?.focus();
});

function submit(): void {
    if (w.phase.value === 'reauth') void w.reauth(password.value);
    else if (w.phase.value === 'review') void w.confirm();
    // A failed proposal (or a failed confirm of an expired one) starts again from a fresh proposal.
    else if (w.phase.value === 'error') start();
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        :title="title"
        :busy="busy"
        :error="w.error.value"
        :destructive="destructive"
        :disabled="w.phase.value === 'proposing' || (w.phase.value === 'reauth' && password.length === 0)"
        :submit-label="submitLabel"
        @submit="submit"
    >
        <p v-if="w.phase.value === 'proposing'" class="text-xs text-muted-foreground" role="status">{{ t('ads.control.write.preparing') }}</p>

        <template v-else-if="w.proposal.value">
            <p v-if="dryRun" data-test="dry-run" class="rounded-md border border-warning/40 bg-warning/15 px-3 py-2 text-xs">
                <span class="font-semibold">{{ t('ads.control.write.dry_run_title') }}</span> {{ t('ads.control.write.dry_run_body') }}
            </p>
            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-xs">
                <dt class="text-muted-foreground">{{ t('ads.actions.account') }}</dt>
                <dd class="font-medium" dir="auto">{{ account }}</dd>
                <template v-if="statusRow">
                    <dt class="text-muted-foreground">{{ t('ads.control.write.current') }}</dt>
                    <dd class="font-medium">{{ statusLabel(statusRow.before) }}</dd>
                    <dt class="text-muted-foreground">{{ t('ads.control.write.next') }}</dt>
                    <dd class="font-semibold" :class="String(statusRow.after).toUpperCase() === 'PAUSED' ? 'text-destructive' : 'text-success'">{{ statusLabel(statusRow.after) }}</dd>
                </template>
                <template v-for="(row, i) in otherRows" :key="`${row.path}-${i}`">
                    <dt class="text-muted-foreground">{{ pathLabel(row.path) }}<template v-if="row.level"> · {{ t(`ads.campaigns.level_${row.level}`) }}</template></dt>
                    <dd class="tabular-nums" :class="row.path === 'daily_budget' && budget ? 'font-semibold' : ''">
                        <bdi>{{ valueLabel(row.before) }}</bdi> <span aria-hidden="true">{{ flowArrow(locale) }}</span> <bdi>{{ valueLabel(row.after) }}</bdi>
                        <span v-if="row.path === 'daily_budget' && pctLabel" class="ms-1 text-muted-foreground">({{ pctLabel }})</span>
                    </dd>
                </template>
                <template v-if="spendToday !== null">
                    <dt class="text-muted-foreground">{{ t('ads.control.write.today_spend') }}</dt>
                    <dd class="tabular-nums">{{ formatAdsMoney(spendToday, locale, currency) }}</dd>
                </template>
            </dl>
            <p v-if="dataAt" class="text-2xs text-muted-foreground">{{ t('ads.control.write.data_age', { time: formatDateTime(dataAt, locale) }) }}</p>
            <p v-if="parentPaused && !stopping && !budget" class="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">{{ t('ads.control.write.parent_paused') }}</p>
            <p v-for="n in notes" :key="n.key" class="rounded-md bg-warning/15 px-3 py-2 text-xs">{{ t('ads.control.write.note_learning_reentry', { days: Number(n.days ?? 0) }) }}</p>

            <div v-if="w.phase.value === 'reauth' || (w.phase.value === 'confirming' && password.length > 0)" class="space-y-1.5 rounded-md border border-border p-3">
                <p class="text-xs font-semibold">{{ t('ads.control.write.reauth_title') }}</p>
                <p class="text-2xs text-muted-foreground">{{ t('ads.control.write.reauth_body') }}</p>
                <label class="block space-y-1 text-xs">
                    <span>{{ t('ads.control.write.password') }}</span>
                    <input ref="passwordInput" v-model="password" data-test="reauth-password" type="password" autocomplete="current-password" class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm" />
                </label>
            </div>
        </template>
    </FormDialog>
</template>
