import { getEcho } from '@/echo';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import type Echo from 'laravel-echo';
import { computed, getCurrentScope, onScopeDispose, ref, type ComputedRef, type Ref } from 'vue';

export const POLL_INTERVAL_MS = 5000;

// Pusher connection state shared by every consumer.
const state = ref<string>('unavailable');
let bound = false;

function bindState(echo: Echo<'reverb'>): void {
    if (bound) {
        return;
    }

    bound = true;
    const connection = echo.connector.pusher.connection;
    state.value = connection.state;
    connection.bind('state_change', (change: { current: string }) => {
        state.value = change.current;
    });
}

export interface EchoHandle {
    /** Echo singleton, or null when broadcasting is not configured. Always use optional chaining. */
    echo: Echo<'reverb'> | null;
    /** Raw pusher state: initialized | connecting | connected | unavailable | failed | disconnected. */
    state: Readonly<Ref<string>>;
    /** True only while the websocket is connected; otherwise consumers should poll. */
    live: ComputedRef<boolean>;
    /** Runs `fn` every 5 s while not live. Stopped automatically when the calling scope is disposed. */
    poll: (fn: () => void | Promise<void>) => () => void;
}

export function useEcho(): EchoHandle {
    const page = usePage<SharedData>();
    const echo = getEcho(page.props.broadcasting ?? null);

    if (echo) {
        bindState(echo);
    }

    const live = computed(() => echo !== null && state.value === 'connected');

    function poll(fn: () => void | Promise<void>): () => void {
        let running = false;
        const timer = window.setInterval(async () => {
            if (live.value || running || document.hidden) {
                return;
            }

            running = true;
            try {
                await fn();
            } catch {
                // A failed poll is retried on the next tick.
            } finally {
                running = false;
            }
        }, POLL_INTERVAL_MS);

        const stop = () => window.clearInterval(timer);

        if (getCurrentScope()) {
            onScopeDispose(stop);
        }

        return stop;
    }

    return { echo, state, live, poll };
}
