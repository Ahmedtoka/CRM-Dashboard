import axios, { AxiosError, type AxiosInstance } from 'axios';

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
