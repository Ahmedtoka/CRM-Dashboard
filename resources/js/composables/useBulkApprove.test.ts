import { useApi } from '@/composables/useApi';
import { useBulkApprove } from '@/composables/useBulkApprove';
import { AxiosError, type AxiosAdapter, type InternalAxiosRequestConfig } from 'axios';
import { afterEach, describe, expect, it } from 'vitest';

const plan = {
    ok: true,
    total_ads: 3,
    approvals_left: 5,
    skipped: { warned: 0, first_launch: 0, self: 0, limit: 0 },
    launches: ['A', 'B', 'C'].map((id) => ({ id, title: id, account: 'LV', ads: 1, revision: 1, checks_hash: 'h' })),
};

function adapter(handler: (config: InternalAxiosRequestConfig) => { status: number; data: unknown }): AxiosAdapter {
    return async (config) => {
        const { status, data } = handler(config as InternalAxiosRequestConfig);
        const response = { data, status, statusText: String(status), headers: {}, config };
        if (status >= 400) throw new AxiosError('fail', String(status), config as InternalAxiosRequestConfig, null, response as never);
        return response;
    };
}

describe('useBulkApprove', () => {
    const api = useApi();
    const original = api.defaults.adapter;
    afterEach(() => (api.defaults.adapter = original));

    it('approves the safe launches one by one and counts the results', async () => {
        const calls: string[] = [];
        api.defaults.adapter = adapter((c) => {
            calls.push(String(c.url));
            if (c.url === '/ads/approvals/bulk') return { status: 200, data: plan };
            const failed = c.url === '/ads/approvals/B/approve';
            return {
                status: 200,
                data: {
                    ok: true,
                    message: 'm',
                    self_approved: false,
                    ads: [],
                    launch: { id: 'x', state: failed ? 'awaiting_approval' : 'live', revision: 2 },
                },
            };
        });
        const bulk = useBulkApprove();

        await bulk.load();
        await bulk.run();

        expect(calls).toEqual(['/ads/approvals/bulk', '/ads/approvals/A/approve', '/ads/approvals/B/approve', '/ads/approvals/C/approve']);
        expect(bulk.done.value).toBe(3);
        expect(bulk.okCount.value).toBe(2);
        expect(bulk.failedCount.value).toBe(1);
    });

    it('stops on the kill switch and leaves the rest waiting', async () => {
        api.defaults.adapter = adapter((c) => {
            if (c.url === '/ads/approvals/bulk') return { status: 200, data: plan };
            if (c.url === '/ads/approvals/B/approve')
                return { status: 422, data: { code: 'checks_failed', message: 'off', details: { keys: ['ap.writes_on'] } } };
            return { status: 200, data: { ok: true, message: 'm', self_approved: false, ads: [], launch: { id: 'x', state: 'live', revision: 2 } } };
        });
        const bulk = useBulkApprove();

        await bulk.load();
        await bulk.run();

        expect(bulk.okCount.value).toBe(1);
        expect(bulk.leftCount.value).toBe(1);
        expect(bulk.stoppedReason.value).toBe('off');
    });

    it('asks for the password when the plan answers 423', async () => {
        api.defaults.adapter = adapter(() => ({ status: 423, data: { message: 'Password confirmation required.' } }));
        const bulk = useBulkApprove();

        await bulk.load();

        expect(bulk.needsReauth.value).toBe(true);
        expect(bulk.plan.value).toBeNull();
    });

    it('resumes the pending launches after a password prompt mid-run', async () => {
        const calls: string[] = [];
        let locked = true;
        api.defaults.adapter = adapter((c) => {
            calls.push(String(c.url));
            if (c.url === '/ads/approvals/bulk') return { status: 200, data: plan };
            if (c.url === '/ads/approvals/B/approve' && locked) return { status: 423, data: { message: 'Password confirmation required.' } };
            return { status: 200, data: { ok: true, message: 'm', self_approved: false, ads: [], launch: { id: 'x', state: 'live', revision: 2 } } };
        });
        const bulk = useBulkApprove();

        await bulk.load();
        await bulk.run();
        expect(bulk.needsReauth.value).toBe(true);
        expect(bulk.leftCount.value).toBe(2);

        locked = false;
        await bulk.run();

        expect(bulk.needsReauth.value).toBe(false);
        expect(bulk.okCount.value).toBe(3);
        expect(calls.filter((u) => u === '/ads/approvals/A/approve')).toHaveLength(1);
    });
});
