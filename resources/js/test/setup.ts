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
