import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { router, apiPost, apiGet } = vi.hoisted(() => ({
    router: { get: vi.fn(), reload: vi.fn(), visit: vi.fn() },
    apiPost: vi.fn(),
    apiGet: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router,
    usePage: () => ({ props: { errors: {} }, url: '/ads/accounts' }),
    useForm: (data: Record<string, unknown>) => {
        const form: Record<string, unknown> = {
            ...data,
            errors: {},
            processing: false,
            post: vi.fn(),
            put: vi.fn(),
            patch: vi.fn(),
            delete: vi.fn(),
            clearErrors: vi.fn(),
        };
        form.defaults = () => ({ reset: vi.fn() });
        form.transform = () => form;
        return form;
    },
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/composables/useApi', () => ({
    useApi: () => ({ post: apiPost, get: apiGet }),
    apiErrorMessage: (_e: unknown, fallback: string) => fallback,
}));

import Accounts from '@/pages/Ads/Accounts.vue';

const account = (id: number, extra: Record<string, unknown> = {}) => ({
    id,
    platform: 'meta',
    external_id: `act_${id}`,
    name: `Account ${id}`,
    currency: 'EGP',
    status: 'ACTIVE',
    is_active: true,
    last_synced_at: '2026-10-06T08:00:00+03:00',
    buyer: null,
    history: [],
    spend: 1500,
    last_run: null,
    ...extra,
});

const props = (extra: Record<string, unknown> = {}) => ({
    connections: [
        {
            id: 1,
            platform: 'meta',
            name: 'Main BM',
            status: 'connected',
            last_error: null,
            last_synced_at: null,
            driver: 'live',
            has_token: true,
            credentials_unreadable: false,
            read_only: false,
            token_health: { valid: true, type: null, scopes: [], expires_at: null, data_access_expires_at: null, checked_at: null },
            configured: { access_token: true },
            accounts: [account(11), account(12, { last_run: { status: 'error', error: 'Boom', finished_at: null } })],
        },
    ],
    link_rate: { rate: 0.5, orders: 10, linked: 5, days: 14 },
    syncing: [],
    buyers: [{ id: 7, name: 'Sara', is_active: true }],
    platforms: [{ value: 'meta', label: 'Meta', fields: [] }],
    filters: { from: '2026-10-01', to: '2026-10-06', accounts: [] },
    summary: { accounts: 2, active: 2, spend: [{ currency: 'EGP', amount: 3000 }], last_sync: '2026-10-06T08:00:00+03:00', errors: 1 },
    account_options: [
        { id: 11, name: 'Account 11', platform: 'meta' },
        { id: 12, name: 'Account 12', platform: 'meta' },
    ],
    ...extra,
});

const stubs = { AppLayout: { template: '<div><slot /></div>' }, PageHeader: { template: '<div><slot /></div>' }, AdsSetupTabs: true };

function render(extra: Record<string, unknown> = {}) {
    return mount(Accounts, { props: props(extra) as never, global: { stubs }, attachTo: document.body });
}

describe('Ads setup › accounts (F6)', () => {
    beforeEach(() => {
        router.get.mockReset();
        router.reload.mockReset();
        apiPost.mockReset();
        apiGet.mockReset();
        document.body.innerHTML = '';
    });

    it('shows the summary tiles and no density toggle or per-platform connect buttons', () => {
        const w = render();
        const tiles = w.get('[data-test="tiles"]').text();
        expect(tiles).toContain('٣٬٠٠٠');
        expect(w.findAll('[data-test="tiles"] > *')).toHaveLength(6);
        expect(w.find('[data-density-option]').exists()).toBe(false);
        expect(w.findAll('[data-test="connect"]')).toHaveLength(1);
    });

    it('loads only on «اعرض», with the range and the picked accounts', async () => {
        const w = render();
        expect(router.get).not.toHaveBeenCalled();
        await w.get('[data-test="show"]').trigger('click');
        expect(router.get).toHaveBeenCalledWith('/ads/accounts', { from: '2026-10-01', to: '2026-10-06' }, expect.objectContaining({ preserveState: true }));
    });

    it('renders row actions as labelled icon buttons', () => {
        const w = render();
        const actions = w.get('[data-test="actions-11"]').findAll('[data-icon-action]');
        expect(actions.length).toBe(4);
        for (const a of actions) {
            expect(a.attributes('aria-label')).toBeTruthy();
            expect(a.text()).toBe('');
        }
        // The connection card actions are icons too.
        expect(w.get('[data-test="connection-1"]').findAll('[data-icon-action]')).toHaveLength(3);
    });

    it('opens the history popover and the more menu from their icons', async () => {
        const w = render();
        const [, history, , more] = w.get('[data-test="actions-11"]').findAll('[data-icon-action]');
        await history.trigger('click');
        await flushPromises();
        expect(document.body.textContent).toContain('تاريخ التسكين');
        expect(history.attributes('aria-expanded')).toBe('true');
        await more.trigger('keydown', { key: 'Enter' });
        await flushPromises();
        expect(document.body.textContent).toContain('إيقاف مزامنة الحساب');
        w.unmount();
    });

    it('one sync: posts the picked accounts, shows one overall bar and stops when finished', async () => {
        apiPost.mockResolvedValue({ data: { since: '2026-10-06T09:00:00Z', accounts: [11, 12], errors: [] } });
        apiGet
            .mockResolvedValueOnce({
                data: {
                    accounts: [
                        { id: 11, name: 'Account 11', platform: 'meta', state: 'done', error: null },
                        { id: 12, name: 'Account 12', platform: 'meta', state: 'running', error: null },
                    ],
                    done: 1,
                    total: 2,
                    finished: false,
                },
            })
            .mockResolvedValueOnce({
                data: {
                    accounts: [
                        { id: 11, name: 'Account 11', platform: 'meta', state: 'done', error: null },
                        { id: 12, name: 'Account 12', platform: 'meta', state: 'error', error: 'Boom' },
                    ],
                    done: 2,
                    total: 2,
                    finished: true,
                },
            });
        const w = render();
        await w.get('[data-test="sync-open"]').trigger('click');
        await flushPromises();
        (document.querySelector('[data-test="sync-scope-some"]') as HTMLInputElement).click();
        await flushPromises();
        (document.querySelector('[data-test="sync-pick-11"]') as HTMLInputElement).click();
        (document.querySelector('[data-test="sync-pick-12"]') as HTMLInputElement).click();
        await flushPromises();
        (document.querySelector('[data-test="sync-start"]') as HTMLButtonElement).click();
        await flushPromises();

        expect(apiPost).toHaveBeenCalledWith('/ads/accounts/sync', { accounts: [11, 12] });
        const bar = w.get('[data-test="sync-progress"] [role="progressbar"]');
        expect(bar.attributes('aria-valuenow')).toBe('1');
        expect(bar.attributes('aria-valuemax')).toBe('2');
        expect(bar.attributes('aria-valuetext')).toBe('١ من ٢ حسابات');
        expect(w.findAll('[data-test^="sync-chip-"]')).toHaveLength(2);

        // The next tick finishes: the page reloads its numbers and polling stops.
        await (w.vm as unknown as { $: { setupState: { poll: () => Promise<void> } } }).$.setupState.poll();
        await flushPromises();
        expect(w.get('[data-test="sync-progress"] [role="progressbar"]').attributes('aria-valuenow')).toBe('2');
        expect(router.reload).toHaveBeenCalledWith({ only: ['connections', 'summary', 'syncing'] });
        await (w.vm as unknown as { $: { setupState: { poll: () => Promise<void> } } }).$.setupState.poll();
        expect(apiGet).toHaveBeenCalledTimes(2);
        w.unmount();
    });

    it('resumes the bar for accounts already syncing when the page opens', async () => {
        apiGet.mockResolvedValue({ data: { accounts: [{ id: 12, name: 'Account 12', platform: 'meta', state: 'running', error: null }], done: 0, total: 1, finished: false } });
        const w = render({ syncing: [12] });
        await flushPromises();
        expect(apiGet).toHaveBeenCalledWith('/ads/accounts/sync-status', expect.objectContaining({ params: expect.objectContaining({ accounts: '12' }) }));
        expect(w.find('[data-test="sync-progress"]').exists()).toBe(true);
        w.unmount();
    });
});
