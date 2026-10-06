import { useApi } from '@/composables/useApi';
import { MIN_VISIBLE_MS, resetLoadingBar, SHOW_DELAY_MS, useLoadingBar } from '@/composables/useLoadingBar';
import axios, { type AxiosAdapter } from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const after = (ms: number): AxiosAdapter => (config) =>
    new Promise((resolve) => setTimeout(() => resolve({ data: {}, status: 200, statusText: 'OK', headers: {}, config }), ms));

describe('useLoadingBar', () => {
    const bar = useLoadingBar();

    beforeEach(() => {
        vi.useFakeTimers();
        resetLoadingBar();
    });
    afterEach(() => vi.useRealTimers());

    it('waits 200 ms before showing, so fast work never flashes', async () => {
        expect(SHOW_DELAY_MS).toBe(200);
        bar.start();
        await vi.advanceTimersByTimeAsync(150);
        bar.done();
        await vi.advanceTimersByTimeAsync(500);
        expect(bar.active.value).toBe(false);
    });

    it('stays visible at least 200 ms once shown, then completes and hides', async () => {
        expect(MIN_VISIBLE_MS).toBe(200);
        bar.start();
        await vi.advanceTimersByTimeAsync(200);
        expect(bar.active.value).toBe(true);
        await vi.advanceTimersByTimeAsync(10);
        bar.done();
        await vi.advanceTimersByTimeAsync(100);
        expect(bar.active.value).toBe(true);
        expect(bar.progress.value).toBeLessThan(100);
        await vi.advanceTimersByTimeAsync(100);
        expect(bar.progress.value).toBe(100);
        await vi.advanceTimersByTimeAsync(250);
        expect(bar.active.value).toBe(false);
    });

    it('counts overlapping work and never goes below zero', async () => {
        bar.start();
        bar.start();
        bar.done();
        expect(bar.pending.value).toBe(1);
        bar.done();
        bar.done();
        expect(bar.pending.value).toBe(0);
    });

    it('drives the bar from useApi requests, except silent ones', async () => {
        const api = useApi();
        api.defaults.adapter = after(500);
        const loud = api.get('/x');
        void api.get('/poll', { silent: true });
        // axios runs request interceptors in a promise chain: flush it first.
        await vi.advanceTimersByTimeAsync(1);
        expect(bar.pending.value).toBe(1);
        await vi.advanceTimersByTimeAsync(250);
        expect(bar.active.value).toBe(true);
        await vi.advanceTimersByTimeAsync(300);
        await loud;
        expect(bar.pending.value).toBe(0);
    });

    it('drives the bar from the default axios instance too', async () => {
        axios.defaults.adapter = after(50);
        const request = axios.get('/raw');
        await vi.advanceTimersByTimeAsync(1);
        expect(bar.pending.value).toBe(1);
        await vi.advanceTimersByTimeAsync(60);
        await request;
        expect(bar.pending.value).toBe(0);
    });
});
