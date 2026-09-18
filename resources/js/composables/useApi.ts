import axios, { AxiosError, type AxiosInstance } from 'axios';
import { useLoadingBar } from './useLoadingBar';

/**
 * Axios for the session-authenticated JSON endpoints (/inbox/..., /products/...).
 * Laravel's XSRF-TOKEN cookie is read on every request, so the token stays valid after login rotation.
 */
const api: AxiosInstance = axios.create({
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    withCredentials: true,
    withXSRFToken: true,
    xsrfCookieName: 'XSRF-TOKEN',
    xsrfHeaderName: 'X-XSRF-TOKEN',
});

// Every request shows the global loading bar unless flagged `{ silent: true }`
// (polling, debounced background refreshes, typing pings). The flag is read from
// the config the response/error carries, so start and done always pair up.
const bar = useLoadingBar();

// Fix round 1: only a config that actually called start() may call done(), so a request
// rejected before this interceptor ran can never decrement another request's count.
const BAR_STARTED = Symbol('loadingBarStarted');
type MarkedConfig = { [BAR_STARTED]?: boolean };

function finish(config: unknown): void {
    const marked = config as MarkedConfig | undefined;
    if (!marked?.[BAR_STARTED]) return;
    marked[BAR_STARTED] = false; // never twice for the same request
    bar.done();
}

api.interceptors.request.use(
    (config) => {
        if (!config.silent) {
            // Non-enumerable, so it never leaks into serialised config or request data.
            Object.defineProperty(config, BAR_STARTED, { value: true, writable: true, enumerable: false, configurable: true });
            bar.start();
        }
        return config;
    },
    (error) => Promise.reject(error),
);

api.interceptors.response.use(
    (response) => {
        finish(response.config);
        return response;
    },
    (error) => {
        finish((error as AxiosError | undefined)?.config);
        return Promise.reject(error);
    },
);

/** Best human-readable message from a failed request (Laravel `{message}` / first validation error / a plain `{error}` payload). */
export function apiErrorMessage(error: unknown, fallback: string): string {
    if (error instanceof AxiosError) {
        const data = error.response?.data as { message?: string; error?: string; errors?: Record<string, string[]> } | undefined;
        const firstError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;

        return firstError ?? data?.message ?? data?.error ?? fallback;
    }

    return fallback;
}

export function useApi(): AxiosInstance {
    return api;
}
