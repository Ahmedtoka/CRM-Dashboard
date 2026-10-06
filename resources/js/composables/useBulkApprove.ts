import { apiErrorMessage, useApi } from '@/composables/useApi';
import type { ApproveResult, BulkPlan } from '@/types/ads';
import { isAxiosError } from 'axios';
import { computed, ref } from 'vue';

export interface BulkRow {
    id: string;
    title: string | null;
    ads: number;
    outcome: 'pending' | 'ok' | 'failed';
    message: string | null;
}

/** Codes that end the whole run (A5): nothing after them can succeed today. */
const STOP_CODES = ['writes_disabled', 'cap_exceeded'];
const STOP_CHECKS = ['ap.activations_left', 'ap.writes_on'];

/** «وافق على الآمن كله»: load the safe plan, then approve one launch at a time (the progress bar follows `done`). */
export function useBulkApprove() {
    const api = useApi();
    const plan = ref<BulkPlan | null>(null);
    const rows = ref<BulkRow[]>([]);
    const done = ref(0);
    const running = ref(false);
    const stoppedReason = ref<string | null>(null);
    const needsReauth = ref(false);
    const error = ref<string | null>(null);

    async function load(): Promise<void> {
        needsReauth.value = false;
        error.value = null;
        plan.value = null;
        stoppedReason.value = null;
        try {
            const { data } = await api.post<BulkPlan>('/ads/approvals/bulk');
            plan.value = data;
            rows.value = data.launches.map((l) => ({ id: l.id, title: l.title, ads: l.ads, outcome: 'pending', message: null }));
            done.value = 0;
        } catch (e) {
            if (isAxiosError(e) && e.response?.status === 423) needsReauth.value = true;
            else error.value = apiErrorMessage(e, 'error');
        }
    }

    async function run(): Promise<void> {
        if (!plan.value || running.value) return;
        running.value = true;
        stoppedReason.value = null;
        for (const [i, item] of plan.value.launches.entries()) {
            if (rows.value[i].outcome !== 'pending') continue;
            try {
                const { data } = await api.post<ApproveResult>(`/ads/approvals/${item.id}/approve`, {
                    revision: item.revision,
                    checks_hash: item.checks_hash,
                    ack_warnings: [],
                });
                const live = data.launch.state === 'live';
                rows.value[i] = { ...rows.value[i], outcome: live ? 'ok' : 'failed', message: data.message };
                done.value = i + 1;
                const capped = data.ads.find((a) => a.code !== null && STOP_CODES.includes(a.code));
                if (!live && capped) {
                    stoppedReason.value = capped.message ?? capped.code;
                    break;
                }
            } catch (e) {
                const status = isAxiosError(e) ? e.response?.status : undefined;
                const body = (isAxiosError(e) ? e.response?.data : undefined) as { code?: string; details?: { keys?: string[] } } | undefined;
                if (status === 423) {
                    needsReauth.value = true;
                    break;
                }
                rows.value[i] = { ...rows.value[i], outcome: 'failed', message: apiErrorMessage(e, 'error') };
                done.value = i + 1;
                const stop =
                    status === 503 ||
                    STOP_CODES.includes(body?.code ?? '') ||
                    (body?.code === 'checks_failed' && (body.details?.keys ?? []).some((k) => STOP_CHECKS.includes(k)));
                if (stop) {
                    stoppedReason.value = rows.value[i].message;
                    break;
                }
            }
        }
        running.value = false;
    }

    const okCount = computed(() => rows.value.filter((r) => r.outcome === 'ok').length);
    const failedCount = computed(() => rows.value.filter((r) => r.outcome === 'failed').length);
    const leftCount = computed(() => rows.value.filter((r) => r.outcome === 'pending').length);

    return { plan, rows, done, running, stoppedReason, needsReauth, error, okCount, failedCount, leftCount, load, run };
}
