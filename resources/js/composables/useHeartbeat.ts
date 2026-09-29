import { useApi } from '@/composables/useApi';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { AxiosError } from 'axios';

/** The server counts a user online for 2 minutes after a heartbeat; one a minute leaves room for a lost request. */
export const HEARTBEAT_INTERVAL_MS = 60_000;

/** Focus and visibility events fire together; beats closer than this are dropped. */
const MIN_GAP_MS = 10_000;

/**
 * Module-level on purpose: `AppLayout` is not a persistent Inertia layout, every page
 * mounts its own, so the timer must outlive the component that happened to start it.
 */
let startedForUserId: number | null = null;
let timer: number | undefined;
let lastBeatAt = 0;
let inFlight = false;
let onVisibility: (() => void) | null = null;
let onFocus: (() => void) | null = null;

function stop(): void {
    window.clearInterval(timer);
    timer = undefined;
    if (onVisibility) document.removeEventListener('visibilitychange', onVisibility);
    if (onFocus) window.removeEventListener('focus', onFocus);
    onVisibility = null;
    onFocus = null;
    startedForUserId = null;
    lastBeatAt = 0;
}

/**
 * Tells the server the CRM is open, so presence (and the queue's offline
 * detection) follows the moderator. A hidden tab keeps beating: moderators work
 * in Shopify and other tabs beside the inbox and must not drop out of the queue
 * for it. She goes offline when the tab is closed or the machine sleeps.
 */
export function useHeartbeat() {
    const api = useApi();
    const page = usePage<SharedData>();

    function beat(): void {
        if (startedForUserId === null) return;

        // Logged out (or another user logged in) since start(): the login page has no AppLayout to stop us.
        if ((page.props.auth?.user?.id ?? null) !== startedForUserId) {
            stop();
            return;
        }

        const now = Date.now();
        if (inFlight || now - lastBeatAt < MIN_GAP_MS) return;

        lastBeatAt = now;
        inFlight = true;
        const userId = startedForUserId;
        api.post('/presence/heartbeat', {}, { silent: true })
            .catch((error: unknown) => {
                // Session gone or account deactivated: nothing to keep alive. Anything else is retried on the next tick.
                const status = error instanceof AxiosError ? error.response?.status : undefined;
                if ((status === 401 || status === 403 || status === 419) && startedForUserId === userId) stop();
            })
            .finally(() => {
                inFlight = false;
            });
    }

    /** Idempotent per user: every page's AppLayout calls this. No user stops it; a different user restarts it. */
    function start(): void {
        const userId = page.props.auth?.user?.id ?? null;

        if (userId === startedForUserId) return;
        if (startedForUserId !== null) stop();
        if (userId === null) return;

        startedForUserId = userId;
        onVisibility = () => {
            if (!document.hidden) beat();
        };
        onFocus = () => beat();
        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('focus', onFocus);
        timer = window.setInterval(beat, HEARTBEAT_INTERVAL_MS);
        beat();
    }

    return { start, stop, beat };
}
