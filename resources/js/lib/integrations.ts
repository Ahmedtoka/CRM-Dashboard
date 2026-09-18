import type { IntegrationAccount } from '@/types/admin';
import { AxiosError } from 'axios';

/** The four states every Integrations card is in (plus "soon" for platforms not offered yet). */
export type CardState = 'not_connected' | 'connecting' | 'connected' | 'problem' | 'soon';

export function accountState(account: IntegrationAccount | null, connecting = false): CardState {
    if (connecting) return 'connecting';
    if (!account || account.status === 'disconnected') return 'not_connected';
    if (account.status === 'error' || account.health_status === 'problem') return 'problem';

    return 'connected';
}

/** A live, not-disconnected account (what "connected" means for the other cards). */
export function isActive(account: IntegrationAccount | null): account is IntegrationAccount {
    return account !== null && account.status !== 'disconnected';
}

const KNOWN_ERRORS = [
    'graph_unreachable',
    'token_invalid',
    'graph_error',
    'page_not_accessible',
    'page_token_unavailable',
    'token_expired',
    'missing_tasks',
    'facebook_not_connected',
    'instagram_not_linked',
    'waba_not_accessible',
    'phone_not_accessible',
    'phone_not_in_waba',
    'subscribe_failed',
] as const;

/**
 * Reads an Integrations endpoint failure: `{error, detail}` (a translatable code plus
 * Meta's own message) or a Laravel validation answer (`{message, errors}`).
 */
export function integrationError(
    error: unknown,
    t: (key: string, params?: Record<string, string>) => string,
): { message: string; detail: string | null } {
    if (error instanceof AxiosError) {
        const data = error.response?.data as
            | { error?: string; detail?: string | null; message?: string; errors?: Record<string, string[]> }
            | undefined;

        if (data?.error && (KNOWN_ERRORS as readonly string[]).includes(data.error)) {
            const detail = data.detail ?? null;
            const message = t(`settings.integrations.errors.${data.error}`, { detail: detail ?? '' });

            // The detail is already part of the message for codes that interpolate it.
            return { message, detail: detail !== null && message.includes(detail) ? null : detail };
        }

        const firstError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
        if (firstError || data?.message) return { message: firstError ?? data?.message ?? '', detail: null };
    }

    return { message: t('settings.integrations.errors.unknown'), detail: null };
}
