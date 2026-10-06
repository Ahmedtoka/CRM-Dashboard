import { vi } from 'vitest';

// jsdom has neither; radix-vue and PageHeader use them.
class NoopResizeObserver {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
}
globalThis.ResizeObserver ??= NoopResizeObserver as unknown as typeof ResizeObserver;
window.matchMedia ??= ((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
})) as unknown as typeof window.matchMedia;

// Components may import `router`; tests never perform real visits. `Link` stays real (renders <a href>).
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/vue3')>();

    return {
        ...actual,
        router: { get: vi.fn(), post: vi.fn(), visit: vi.fn(), reload: vi.fn(), push: vi.fn(), replace: vi.fn(), on: vi.fn(() => () => {}) },
    };
});

// Node 26 ships a global localStorage getter that is undefined without --localstorage-file and shadows jsdom's.
if (typeof globalThis.localStorage === 'undefined' || typeof globalThis.localStorage?.clear !== 'function') {
    const store = new Map<string, string>();
    const shim = {
        get length() {
            return store.size;
        },
        clear: () => store.clear(),
        getItem: (k: string) => store.get(k) ?? null,
        key: (i: number) => [...store.keys()][i] ?? null,
        removeItem: (k: string) => void store.delete(k),
        setItem: (k: string, v: string) => void store.set(k, String(v)),
    };
    Object.defineProperty(globalThis, 'localStorage', { value: shim, configurable: true });
    Object.defineProperty(window, 'localStorage', { value: shim, configurable: true });
}
