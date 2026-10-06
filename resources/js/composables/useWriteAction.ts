import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { newIdempotencyKey } from '@/lib/ads';
import type { AdLevel } from '@/types/ads';
import { ref } from 'vue';

export type WritePhase = 'idle' | 'proposing' | 'review' | 'reauth' | 'confirming' | 'done' | 'pending' | 'error';

export interface WriteTarget {
    accountId: number;
    level: AdLevel;
    externalId: string;
    to: 'active' | 'paused';
    reason?: string | null;
    /** `alert` when the write starts from a decisions-feed card (S5); `sourceRef` is the alert id. */
    source?: 'ui' | 'alert';
    sourceRef?: string | null;
}

export interface WriteProposal {
    action: { id: string; state: string; from_status: string | null; to: 'active' | 'paused'; name: string; account: string; expires_at: string | null };
    diff: { path: string; before: unknown; after: unknown; level?: string }[];
    diff_hash: string;
    notes: ({ key: string } & Record<string, unknown>)[];
}

type ApiError = { response?: { status?: number; data?: { code?: string } } };

/** Phase B write pipeline from the browser (spec 4.5): propose → server diff → confirm; a Run may need the password first. */
export function useWriteAction() {
    const api = useApi();
    const { t } = useI18n();
    const phase = ref<WritePhase>('idle');
    const proposal = ref<WriteProposal | null>(null);
    const error = ref<string | null>(null);
    const message = ref<string | null>(null);

    async function propose(target: WriteTarget): Promise<void> {
        // A retry replaces an earlier proposal: cancel it so no stale proposal waits on the server.
        const stale = proposal.value?.action.id;
        if (stale) await api.post(`/ads/write-actions/${stale}/cancel`).catch(() => undefined);
        phase.value = 'proposing';
        error.value = null;
        proposal.value = null;
        try {
            const { data } = await api.post<WriteProposal>(
                '/ads/write-actions',
                {
                    type: 'set_status',
                    account_id: target.accountId,
                    target: { level: target.level, external_id: target.externalId },
                    params: { to: target.to },
                    reason: target.reason ?? null,
                    ...(target.source === 'alert' ? { source: 'alert', source_ref: target.sourceRef ?? null } : {}),
                },
                { headers: { 'Idempotency-Key': newIdempotencyKey() } },
            );
            proposal.value = data;
            phase.value = 'review';
        } catch (e) {
            error.value = apiErrorMessage(e, t('ads.control.write.failed'));
            phase.value = 'error';
        }
    }

    async function confirm(): Promise<void> {
        if (!proposal.value) return;
        phase.value = 'confirming';
        error.value = null;
        try {
            const res = await api.post(`/ads/write-actions/${proposal.value.action.id}/confirm`, { diff_hash: proposal.value.diff_hash });
            message.value = (res.data as { message?: string } | null)?.message ?? null;
            phase.value = res.status === 202 ? 'pending' : 'done';
        } catch (e) {
            const r = (e as ApiError).response;
            if (r?.status === 423 && r.data?.code === 'password_confirmation_required') {
                phase.value = 'reauth';
                return;
            }
            error.value = apiErrorMessage(e, t('ads.control.write.failed'));
            phase.value = 'error';
        }
    }

    async function reauth(password: string): Promise<void> {
        error.value = null;
        phase.value = 'confirming';
        try {
            await api.post('/ads/reauth', { password });
        } catch (e) {
            // Wrong password (422) or throttled: stay on the password step with the server's message.
            error.value = apiErrorMessage(e, t('ads.control.write.failed'));
            phase.value = 'reauth';
            return;
        }
        await confirm();
    }

    async function cancel(): Promise<void> {
        const id = proposal.value?.action.id;
        if (id && (phase.value === 'review' || phase.value === 'reauth')) {
            await api.post(`/ads/write-actions/${id}/cancel`).catch(() => undefined);
        }
        reset();
    }

    function reset(): void {
        phase.value = 'idle';
        proposal.value = null;
        error.value = null;
        message.value = null;
    }

    return { phase, proposal, error, message, propose, confirm, reauth, cancel, reset };
}
