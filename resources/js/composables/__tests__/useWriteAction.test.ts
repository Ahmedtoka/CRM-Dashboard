import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();
vi.mock('@/composables/useApi', () => ({
    useApi: () => ({ post }),
    apiErrorMessage: (e: { response?: { data?: { message?: string } } }, f: string) => e?.response?.data?.message ?? f,
}));

import { useWriteAction } from '@/composables/useWriteAction';

const proposal = {
    action: { id: 'wa_1', state: 'proposed', from_status: 'PAUSED', to: 'active', name: 'Ad', account: 'Acc', expires_at: null },
    diff: [{ path: 'status', before: 'PAUSED', after: 'ACTIVE' }],
    diff_hash: 'h'.repeat(64),
    notes: [],
};
const target = { accountId: 1, level: 'ad' as const, externalId: '123', to: 'active' as const };

describe('useWriteAction', () => {
    beforeEach(() => post.mockReset());

    it('proposes with an idempotency key and shows the server diff', async () => {
        post.mockResolvedValueOnce({ status: 201, data: proposal });
        const w = useWriteAction();
        await w.propose(target);
        expect(post.mock.calls[0][0]).toBe('/ads/write-actions');
        expect(post.mock.calls[0][1]).toMatchObject({ type: 'set_status', account_id: 1, target: { level: 'ad', external_id: '123' }, params: { to: 'active' } });
        expect(post.mock.calls[0][2].headers['Idempotency-Key']).toMatch(/^[A-Za-z0-9:_-]{8,100}$/);
        expect(w.phase.value).toBe('review');
        expect(w.proposal.value?.diff_hash).toBe(proposal.diff_hash);
    });

    it('asks for the password on 423 and confirms again after it', async () => {
        post.mockResolvedValueOnce({ status: 201, data: proposal })
            .mockRejectedValueOnce({ response: { status: 423, data: { code: 'password_confirmation_required', message: 'm' } } })
            .mockResolvedValueOnce({ status: 204, data: '' })
            .mockResolvedValueOnce({ status: 200, data: { action: { state: 'succeeded' }, message: 'ok' } });
        const w = useWriteAction();
        await w.propose(target);
        await w.confirm();
        expect(w.phase.value).toBe('reauth');
        await w.reauth('secret');
        expect(post.mock.calls[2]).toEqual(['/ads/reauth', { password: 'secret' }]);
        expect(post.mock.calls[3][0]).toBe('/ads/write-actions/wa_1/confirm');
        expect(w.phase.value).toBe('done');
    });

    it('keeps the password step with the error when the password is wrong', async () => {
        post.mockResolvedValueOnce({ status: 201, data: proposal })
            .mockRejectedValueOnce({ response: { status: 423, data: { code: 'password_confirmation_required', message: 'm' } } })
            .mockRejectedValueOnce({ response: { status: 422, data: { message: 'wrong password' } } });
        const w = useWriteAction();
        await w.propose(target);
        await w.confirm();
        await w.reauth('nope');
        expect(w.phase.value).toBe('reauth');
        expect(w.error.value).toBe('wrong password');
    });

    it('reports a 202 as pending, not as done', async () => {
        post.mockResolvedValueOnce({ status: 201, data: proposal }).mockResolvedValueOnce({ status: 202, data: { message: 'later' } });
        const w = useWriteAction();
        await w.propose(target);
        await w.confirm();
        expect(w.phase.value).toBe('pending');
        expect(w.message.value).toBe('later');
    });

    it('shows a refusal message', async () => {
        post.mockRejectedValueOnce({ response: { status: 403, data: { code: 'out_of_scope', message: 'not yours' } } });
        const w = useWriteAction();
        await w.propose(target);
        expect(w.phase.value).toBe('error');
        expect(w.error.value).toBe('not yours');
    });
});
