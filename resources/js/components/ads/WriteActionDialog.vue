<script setup lang="ts">
/**
 * Stop / Run confirm (spec 4.5, U X6): the server's diff (current → new status, budget rows on Run), today's spend and
 * the data age. A Run asks for the password when the 15-minute window has passed (423 → password step).
 * The reason travels with the proposal (the diff is bound to it), so there is no reason field after proposing.
 */
import FormDialog from '@/components/crm/FormDialog.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { useWriteAction } from '@/composables/useWriteAction';
import { flowArrow, formatAdsMoney } from '@/lib/ads';
import { formatDateTime } from '@/lib/format';
import type { AdLevel } from '@/types/ads';
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        accountId: number;
        account: string;
        platform: string;
        level: AdLevel;
        externalId: string;
        name: string;
        to: 'active' | 'paused';
        spendToday?: number | null;
        dataAt?: string | null;
        currency?: string;
        reason?: string;
        parentPaused?: boolean;
    }>(),
    { spendToday: null, dataAt: null, currency: 'EGP', reason: '', parentPaused: false },
);
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ done: [status: 'active' | 'paused'] }>();

const { t, locale } = useI18n();
const toast = useToast();
const w = useWriteAction();
const password = ref('');
const stopping = computed(() => props.to === 'paused');
const target = () => ({ accountId: props.accountId, level: props.level, externalId: props.externalId, to: props.to, reason: props.reason || null });

watch(open, (o) => {
    if (o) {
        password.value = '';
        void w.propose(target());
    } else if (w.phase.value === 'review' || w.phase.value === 'reauth') {
        void w.cancel();
    }
});

watch(w.phase, (p) => {
    if (p === 'done' || p === 'pending') {
        toast.push(w.message.value ?? t(stopping.value ? 'ads.control.write.done_stop' : 'ads.control.write.done_run'), p === 'done' ? 'success' : 'info');
        open.value = false;
        emit('done', props.to);
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
const valueLabel = (v: unknown) => (v === null || v === undefined || v === '' ? '—' : typeof v === 'number' ? formatAdsMoney(v, locale.value, props.currency) : String(v));
const statusRow = computed(() => w.proposal.value?.diff.find((d) => d.path === 'status') ?? null);
const otherRows = computed(() => w.proposal.value?.diff.filter((d) => d.path !== 'status') ?? []);
const notes = computed(() => (w.proposal.value?.notes ?? []).filter((n) => n.key === 'learning_reentry'));
const busy = computed(() => ['proposing', 'confirming'].includes(w.phase.value));
const submitLabel = computed(() =>
    w.phase.value === 'reauth' ? t('ads.control.write.reauth_submit') : t(stopping.value ? 'ads.control.write.confirm_stop' : 'ads.control.write.confirm_run'),
);

function submit(): void {
    if (w.phase.value === 'reauth') void w.reauth(password.value);
    else if (w.phase.value === 'review') void w.confirm();
    // A failed proposal (or a failed confirm of an expired one) starts again from a fresh proposal.
    else if (w.phase.value === 'error') void w.propose(target());
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        :title="t(stopping ? 'ads.control.write.stop_title' : 'ads.control.write.run_title', { name })"
        :busy="busy"
        :error="w.error.value"
        :destructive="stopping"
        :disabled="w.phase.value === 'proposing' || (w.phase.value === 'reauth' && password.length === 0)"
        :submit-label="submitLabel"
        @submit="submit"
    >
        <p v-if="w.phase.value === 'proposing'" class="text-xs text-muted-foreground" role="status">{{ t('ads.control.write.preparing') }}</p>

        <template v-else-if="w.proposal.value">
            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-xs">
                <dt class="text-muted-foreground">{{ t('ads.actions.account') }}</dt>
                <dd class="font-medium" dir="auto">{{ account }}</dd>
                <template v-if="statusRow">
                    <dt class="text-muted-foreground">{{ t('ads.control.write.current') }}</dt>
                    <dd class="font-medium">{{ statusLabel(statusRow.before) }}</dd>
                    <dt class="text-muted-foreground">{{ t('ads.control.write.next') }}</dt>
                    <dd class="font-semibold" :class="stopping ? 'text-destructive' : 'text-success'">{{ statusLabel(statusRow.after) }}</dd>
                </template>
                <template v-for="(row, i) in otherRows" :key="`${row.path}-${i}`">
                    <dt class="text-muted-foreground">{{ pathLabel(row.path) }}</dt>
                    <dd class="tabular-nums">
                        <bdi>{{ valueLabel(row.before) }}</bdi> <span aria-hidden="true">{{ flowArrow(locale) }}</span> <bdi>{{ valueLabel(row.after) }}</bdi>
                    </dd>
                </template>
                <template v-if="spendToday !== null">
                    <dt class="text-muted-foreground">{{ t('ads.control.write.today_spend') }}</dt>
                    <dd class="tabular-nums">{{ formatAdsMoney(spendToday, locale, currency) }}</dd>
                </template>
            </dl>
            <p v-if="dataAt" class="text-2xs text-muted-foreground">{{ t('ads.control.write.data_age', { time: formatDateTime(dataAt, locale) }) }}</p>
            <p v-if="parentPaused && !stopping" class="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">{{ t('ads.control.write.parent_paused') }}</p>
            <p v-for="n in notes" :key="n.key" class="rounded-md bg-warning/15 px-3 py-2 text-xs">{{ t('ads.control.write.note_learning_reentry', { days: Number(n.days ?? 0) }) }}</p>

            <div v-if="w.phase.value === 'reauth' || (w.phase.value === 'confirming' && password.length > 0)" class="space-y-1.5 rounded-md border border-border p-3">
                <p class="text-xs font-semibold">{{ t('ads.control.write.reauth_title') }}</p>
                <p class="text-2xs text-muted-foreground">{{ t('ads.control.write.reauth_body') }}</p>
                <label class="block space-y-1 text-xs">
                    <span>{{ t('ads.control.write.password') }}</span>
                    <input v-model="password" type="password" autocomplete="current-password" class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm" />
                </label>
            </div>
        </template>
    </FormDialog>
</template>
